<?php

namespace App\Actions\Orders\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Enums\AuditEvent;
use App\Enums\DeadlineKind;
use App\Enums\ExtensionRequestState;
use App\Enums\OrderEvent;
use App\Enums\OrderState;
use App\Exceptions\DomainApiException;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderDeadlineExtension;
use App\Models\OrderExtensionRequest;
use App\Models\Staff;
use App\Support\RequestContext;
use App\Support\WorkingHours\WorkingHoursResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff answer a seller's request for more time (spec 014 US4, FR-024–FR-025;
 * `order.extend_deadline`). Accept: the new reach-branch deadline is the
 * current one plus 6, 12, 24 or 48 working hours at the order's branch (the
 * working-hours resolver), written through the spec 012 extend in the same
 * transaction (its guards, audit and both-party message), and linked to the
 * request. Refuse: the deadline stays; the seller is told with the note.
 */
final class AnswerExtensionRequestAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public const HOURS = [6, 12, 24, 48];

    public function __construct(
        private readonly ExtendOrderDeadlineAction $extend,
        private readonly WorkingHoursResolver $hours,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function accept(Staff $actor, string $requestId, int $hours, string $note, ?RequestContext $ctx = null): OrderExtensionRequest
    {
        $note = trim($note);

        return DB::transaction(function () use ($actor, $requestId, $hours, $note, $ctx) {
            [$request, $order] = $this->lock($requestId);

            $current = $order->reach_branch_deadline;
            if ($order->state !== OrderState::AWAITING_DELIVERY || $current === null || ! $current->isFuture()) {
                throw DomainApiException::deadlineNotRunning();
            }

            $new = $this->hours->addWorkingMinutes($current, $hours * 60, (int) $order->branch_id);
            $this->extend->handle($actor, $order->order_id, DeadlineKind::REACH_BRANCH, $new, $note, $ctx, $request->request_id);

            $extension = OrderDeadlineExtension::query()->where('extension_request_id', $request->request_id)->firstOrFail();

            $request->forceFill([
                'state' => ExtensionRequestState::ACCEPTED,
                'answered_by' => $actor->staff_id,
                'answered_at' => CarbonImmutable::now(),
                'answer_note' => $note,
                'hours_granted' => $hours,
                'extension_id' => $extension->extension_id,
            ])->save();

            $this->audit->execute(AuditEvent::ORDER_EXTENSION_REQUEST_ACCEPTED, 'success',
                ['order_ref' => $order->order_ref, 'hours' => $hours, 'new_deadline' => $new->toIso8601String()],
                'order', $order->order_id, $ctx, actorStaffId: $actor->staff_id, reason: $note);

            return $request;
        });
    }

    public function refuse(Staff $actor, string $requestId, string $note, ?RequestContext $ctx = null): OrderExtensionRequest
    {
        $note = trim($note);

        return DB::transaction(function () use ($actor, $requestId, $note, $ctx) {
            [$request, $order, $listing] = $this->lock($requestId);

            $request->forceFill([
                'state' => ExtensionRequestState::REFUSED,
                'answered_by' => $actor->staff_id,
                'answered_at' => CarbonImmutable::now(),
                'answer_note' => $note,
            ])->save();

            $this->audit->execute(AuditEvent::ORDER_EXTENSION_REQUEST_REFUSED, 'success',
                ['order_ref' => $order->order_ref], 'order', $order->order_id, $ctx, actorStaffId: $actor->staff_id, reason: $note);

            $this->tellOrder($order->seller_id, OrderEvent::EXTENSION_REFUSED, $order, $listing,
                deadline: $order->reach_branch_deadline, message: $note);
            $this->flushOrderOutbox();

            return $request;
        });
    }

    /** @return array{0: OrderExtensionRequest, 1: Order, 2: Listing} listing → order → request, then re-checked */
    private function lock(string $requestId): array
    {
        $orderId = OrderExtensionRequest::query()->whereKey($requestId)->firstOrFail(['order_id'])->order_id;
        $listing = $this->lockListing(Order::query()->whereKey($orderId)->value('listing_id'));
        $order = $this->lockOrder($orderId);
        $this->assertNotFrozen($order);
        $request = OrderExtensionRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
        if ($request->state !== ExtensionRequestState::WAITING) {
            throw DomainApiException::illegalExtensionRequestTransition();
        }

        return [$request, $order, $listing];
    }
}
