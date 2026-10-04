<?php

namespace App\Actions\Disputes\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Enums\AuditEvent;
use App\Enums\DisputeChangeKind;
use App\Enums\DisputeReason;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Enums\UploadPurpose;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Dispute;
use App\Models\DisputeChange;
use App\Models\DisputePhoto;
use App\Models\Order;
use App\Models\OrderStateChange;
use App\Services\UploadTokenStore;
use App\Support\DatabaseActor;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A party reports a problem and the order freezes (spec 014 US1, FR-001–FR-006).
 * One transaction in the audited `order` scope, listing then order locked
 * (research R12): the party's one dispute per order, the order in one of the
 * four freezable states, the photos claimed from their upload tokens, the
 * dispute + its history row, the order moved to `disputed` (the sweep skips
 * it; every other action answers `order_frozen`). Both parties are told the
 * order is on hold — never what the dispute says. A suspended customer may
 * open one (verified gate, not trade).
 */
final class OpenDisputeAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    /** The states an order can be frozen from (Clarification Q1). */
    public const FREEZABLE = [
        OrderState::AT_INSPECTION, OrderState::WEIGHT_ADJUST_PENDING,
        OrderState::AWAITING_BALANCE, OrderState::READY_TO_COLLECT,
    ];

    public function __construct(
        private readonly UploadTokenStore $uploads,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @param  list<string>  $photoTokens  0–5 `dispute_photo` upload tokens */
    public function handle(Customer $raiser, string $orderId, DisputeReason $reason, string $detail, array $photoTokens, ?RequestContext $ctx = null): Dispute
    {
        $order = Order::query()->findOrFail($orderId);
        if (! $order->isParty($raiser->customer_id)) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
        }
        $raisedAs = $order->buyer_id === $raiser->customer_id ? 'buyer' : 'seller';

        if ($reason->buyerOnly() && $raisedAs !== 'buyer') {
            throw ValidationException::withMessages(['reason' => 'Only the buyer can report that the piece is not the seller\'s to sell.']);
        }

        $photos = [];
        foreach (array_values($photoTokens) as $i => $token) {
            $entry = $this->uploads->resolveEntry($token, $raiser->customer_id, UploadPurpose::DISPUTE_PHOTO);
            if ($entry === null) {
                throw DomainApiException::uploadTokenInvalid();
            }
            $photos[] = ['position' => $i + 1, 'storage_ref' => $entry['storage_ref'], 'mime' => $entry['mime'] ?? 'image/jpeg'];
        }

        $dispute = DatabaseActor::order(fn () => DB::transaction(function () use ($raiser, $order, $raisedAs, $reason, $detail, $photos, $ctx) {
            $listing = $this->lockListing($order->listing_id);
            $order = $this->lockOrder($order->order_id);

            if (Dispute::query()->where('order_id', $order->order_id)->where('raised_by', $raiser->customer_id)->exists()) {
                throw DomainApiException::disputeAlreadyRaised();
            }
            $this->assertNotFrozen($order);
            if (! in_array($order->state, self::FREEZABLE, true)) {
                throw DomainApiException::illegalOrderTransition();
            }

            $from = $order->state;
            $now = CarbonImmutable::now();
            $dispute = Dispute::query()->create([
                'order_id' => $order->order_id,
                'raised_by' => $raiser->customer_id,
                'raised_as' => $raisedAs,
                'reason' => $reason,
                'detail' => $detail,
                'frozen_from' => $from,
                // The application clock, as every order deadline: resume gives back now − frozen_at.
                'frozen_at' => $now,
                'opened_at' => $now,
            ]);
            $dispute->refresh();

            foreach ($photos as $photo) {
                DisputePhoto::query()->create(['dispute_id' => $dispute->dispute_id] + $photo);
            }
            DisputeChange::query()->create([
                'dispute_id' => $dispute->dispute_id,
                'kind' => DisputeChangeKind::OPENED,
                'actor_customer_id' => $raiser->customer_id,
            ]);

            $this->moveOrder($order, OrderState::DISPUTED, $raiser, null, OrderStateChange::NOTE_DISPUTE_OPENED);

            $this->audit->execute(
                AuditEvent::DISPUTE_OPENED,
                'success',
                ['dispute_ref' => $dispute->dispute_ref, 'order_ref' => $order->order_ref, 'raised_as' => $raisedAs,
                    'reason' => $reason->value, 'frozen_from' => $from->value, 'photos' => count($photos)],
                'dispute',
                $dispute->dispute_id,
                $ctx,
                actorCustomerId: $raiser->customer_id,
                before: ['state' => $from->value],
            );

            $this->tellOrder($order->buyer_id, OrderEvent::DISPUTE_OPENED, $order, $listing);
            $this->tellOrder($order->seller_id, OrderEvent::DISPUTE_OPENED, $order, $listing);
            $this->flushOrderOutbox();

            return $dispute;
        }));

        foreach ($photoTokens as $token) {
            $this->uploads->forget($token);
        }

        return $dispute;
    }
}
