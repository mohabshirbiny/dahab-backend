<?php

namespace App\Actions\Orders\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Enums\AuditEvent;
use App\Enums\ExtensionRequestReason;
use App\Enums\ExtensionRequestState;
use App\Enums\OrderState;
use App\Enums\PieceCategory;
use App\Enums\StaffNoticeKind;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderExtensionRequest;
use App\Support\Controls\Controls;
use App\Support\DatabaseActor;
use App\Support\Notices\WorkNotices;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The seller asks for more time to bring the piece (spec 014 US4, FR-022): a
 * reason and a line, no time — staff choose it. Only while the order waits for
 * delivery and before its deadline; one waiting request at a time; the
 * deadline keeps running. Audited `order` scope, verified gate.
 */
final class RequestMoreTimeAction
{
    use MovesOrder;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Customer $seller, string $orderId, ExtensionRequestReason $reason, string $detail, ?RequestContext $ctx = null): OrderExtensionRequest
    {
        $order = Order::query()->findOrFail($orderId);
        if ($order->seller_id !== $seller->customer_id) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
        }

        return DatabaseActor::order(fn () => DB::transaction(function () use ($seller, $order, $reason, $detail, $ctx) {
            $order = $this->lockOrder($order->order_id);
            $this->assertNotFrozen($order);
            // Spec 019 US7 D4: the category is paused, so no request for more time; the order keeps its deadline.
            $category = Listing::query()->whereKey($order->listing_id)->value('category');
            if ($category !== null) {
                app(Controls::class)->assertNotPaused(PieceCategory::from((string) ($category instanceof \BackedEnum ? $category->value : $category)));
            }
            if ($order->state !== OrderState::AWAITING_DELIVERY) {
                throw DomainApiException::illegalOrderTransition();
            }
            if ($order->reach_branch_deadline === null || ! $order->reach_branch_deadline->isFuture()) {
                throw DomainApiException::deadlineNotRunning();
            }
            if (OrderExtensionRequest::query()->where('order_id', $order->order_id)
                ->where('state', ExtensionRequestState::WAITING->value)->exists()) {
                throw DomainApiException::extensionRequestPending();
            }
            // Testing notes: a refusal is the answer for this order; staff may still extend the deadline themselves.
            if (OrderExtensionRequest::query()->where('order_id', $order->order_id)
                ->where('state', ExtensionRequestState::REFUSED->value)->exists()) {
                throw DomainApiException::extensionRequestRefused();
            }

            $request = OrderExtensionRequest::query()->create([
                'order_id' => $order->order_id,
                'seller_id' => $seller->customer_id,
                'reason' => $reason,
                'detail' => $detail,
                'deadline_at_request' => $order->reach_branch_deadline,
            ]);

            $this->audit->execute(AuditEvent::ORDER_EXTENSION_REQUESTED, 'success',
                ['order_ref' => $order->order_ref, 'reason' => $reason->value, 'deadline' => $order->reach_branch_deadline->toIso8601String()],
                'order', $order->order_id, $ctx, actorCustomerId: $seller->customer_id);

            // Spec 026 E8: the branch's bell, after commit.
            WorkNotices::record(StaffNoticeKind::WORK_EXTENSION, $order->order_id, $order->order_ref, (int) $order->branch_id);

            return $request->refresh();
        }));
    }
}
