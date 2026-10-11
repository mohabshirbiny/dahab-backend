<?php

namespace App\Actions\Controls;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Orders\Staff\AnswerExtensionRequestAction;
use App\Enums\AuditEvent;
use App\Enums\ControlLevel;
use App\Enums\ExtensionRequestState;
use App\Enums\ListingState;
use App\Enums\PieceCategory;
use App\Exceptions\DomainApiException;
use App\Jobs\NotifyCustomerJob;
use App\Models\CategoryControl;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderExtensionRequest;
use App\Models\Staff;
use App\Notifications\CategoryNotification;
use App\Notifications\FounderAlertNotification;
use App\Notifications\Templates\Templates;
use App\Support\RequestContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Switch a control on (spec 019 US7, FR-070–FR-072; Part 2 `POST /admin/category-controls`).
 *
 * `stop_new_listings` and `pause_category` act on one category; `stop_everything` writes one
 * row per category in the same transaction. A reason is required and recorded with the
 * message customers see (their default when staff leave it empty). The caller has already
 * checked the level's permission.
 *
 * Effects written here, all in the transaction:
 *  - pausing refuses every waiting request for more time on an order in the category, in the
 *    name of the staff member who paused it; the order keeps its deadline (D4);
 * and after it commits:
 *  - pausing tells only the sellers with a piece in the category;
 *  - stopping everything emails the founders (D5).
 *
 * Nothing with a locked price is touched, and no money moves.
 */
final class SetCategoryControlAction
{
    /** Listing states a seller still has a stake in: they are told about a pause. */
    private const SELLER_STATES = [
        ListingState::LIVE, ListingState::RESERVED, ListingState::IN_REVIEW, ListingState::CHANGES_REQUESTED,
    ];

    public function __construct(
        private readonly RecordAuditLogAction $audit,
        private readonly AnswerExtensionRequestAction $answers,
    ) {}

    /** @return list<CategoryControl> the rows written */
    public function handle(
        Staff $actor,
        ControlLevel $level,
        ?PieceCategory $category,
        string $reason,
        ?string $messageEn,
        ?string $messageAr,
        ?RequestContext $ctx = null,
    ): array {
        $reason = trim($reason);
        $en = trim((string) $messageEn);
        $ar = trim((string) $messageAr);
        if ($en === '') {
            $en = $this->defaultMessage($level, false);
        }
        if ($ar === '') {
            $ar = $this->defaultMessage($level, true);
        }

        $targets = $level === ControlLevel::STOP_EVERYTHING ? PieceCategory::cases() : [$category];
        if (in_array(null, $targets, true)) {
            throw DomainApiException::controlCategoryRequired();
        }

        $rows = DB::transaction(function () use ($actor, $level, $targets, $reason, $en, $ar, $ctx) {
            // Two staff switching at once: one wins, the other is told it is already on.
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('category_control'))");

            $already = CategoryControl::query()->where('is_active', true)->where('level', $level->value)
                ->whereIn('category', array_map(fn (PieceCategory $c) => $c->value, $targets))->exists();
            if ($already) {
                throw DomainApiException::controlAlreadyActive();
            }

            $rows = [];
            foreach ($targets as $target) {
                $rows[] = CategoryControl::query()->create([
                    'category' => $target->value,
                    'level' => $level->value,
                    'is_active' => true,
                    'message_en' => $en,
                    'message_ar' => $ar,
                    'reason' => $reason,
                    'set_by' => $actor->staff_id,
                ])->refresh();
            }

            $refused = $level === ControlLevel::PAUSE_CATEGORY ? $this->refuseWaitingRequests($actor, $targets[0], $ctx) : 0;

            $this->audit->execute(
                AuditEvent::CONTROL_SET,
                'success',
                [
                    'level' => $level->value,
                    'categories' => array_map(fn (PieceCategory $c) => $c->value, $targets),
                    'message_en' => $en,
                    'message_ar' => $ar,
                    'extension_requests_refused' => $refused,
                ],
                entityType: 'category_control',
                entityId: $rows[0]->control_id,
                ctx: $ctx,
                actorStaffId: $actor->staff_id,
                reason: $reason,
            );

            return $rows;
        });

        $this->tellAfterCommit($actor, $level, $targets, $reason, $en, $ar);

        return $rows;
    }

    private function defaultMessage(ControlLevel $level, bool $arabic): string
    {
        return Templates::text(
            $level === ControlLevel::STOP_EVERYTHING ? 'controls.platform_stopped.message' : 'controls.category_stopped.message',
            $arabic,
        );
    }

    /** Every waiting request in the category is answered *refused, category paused*; returns how many. */
    private function refuseWaitingRequests(Staff $actor, PieceCategory $category, ?RequestContext $ctx): int
    {
        $orderIds = Order::query()
            ->whereIn('listing_id', Listing::query()->where('category', $category->value)->select('listing_id'))
            ->select('order_id');

        $ids = OrderExtensionRequest::query()
            ->where('state', ExtensionRequestState::WAITING->value)
            ->whereIn('order_id', $orderIds)
            ->orderBy('requested_at')
            ->pluck('request_id');

        // One message for both languages: the seller reads the language they know.
        $note = Templates::text('controls.extension_refused.note', false).' / '.Templates::text('controls.extension_refused.note', true);

        $refused = 0;
        foreach ($ids as $id) {
            try {
                $this->answers->refuse($actor, (string) $id, $note, $ctx);
                $refused++;
            } catch (DomainApiException) {
                // An order that is frozen for a dispute (or already answered) is left as it is: it lapses by itself.
            }
        }

        return $refused;
    }

    /** @param  array<int, PieceCategory>  $targets */
    private function tellAfterCommit(Staff $actor, ControlLevel $level, array $targets, string $reason, string $en, string $ar): void
    {
        if ($level === ControlLevel::PAUSE_CATEGORY) {
            $category = $targets[0];
            $sellers = Listing::query()->where('category', $category->value)
                ->whereIn('state', array_map(fn (ListingState $s) => $s->value, self::SELLER_STATES))
                ->distinct()->pluck('seller_id');

            $this->notifySellers($sellers, new CategoryNotification($category, $en, $ar));
        }

        if ($level === ControlLevel::STOP_EVERYTHING) {
            self::alertFounders(true, $actor->full_name, $reason);
        }
    }

    /** @param  Collection<int, mixed>  $sellers */
    private function notifySellers(Collection $sellers, CategoryNotification $notification): void
    {
        foreach ($sellers as $sellerId) {
            NotifyCustomerJob::dispatch((string) $sellerId, clone $notification);
        }
    }

    /** Email every founder who has an address (D5). */
    public static function alertFounders(bool $stopped, string $actorName, string $reason): void
    {
        $emails = Staff::query()->where('is_founder', true)->where('is_active', true)
            ->whereNotNull('email')->pluck('email')->filter()->unique()->values();

        foreach ($emails as $email) {
            Notification::route('mail', (string) $email)->notify(new FounderAlertNotification($stopped, $actorName, $reason));
        }
    }
}
