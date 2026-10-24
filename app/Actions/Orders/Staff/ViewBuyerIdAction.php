<?php

namespace App\Actions\Orders\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Identity\ViewIdentityDocumentAction;
use App\Enums\AuditEvent;
use App\Enums\IdentityDocumentStatus;
use App\Enums\OrderState;
use App\Models\IdentityDocument;
use App\Models\Order;
use App\Models\OrderCollection;
use App\Models\Staff;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The counter opens one side of the buyer's own ID to check the person
 * collecting (docs/features/handover-id-check.md): the buyer's latest verified
 * identity document, only while the order waits for collection. The image goes
 * through the identity view (document view log + `identity.document.viewed`),
 * and the order gets `order.buyer_id_viewed`. 404 otherwise.
 */
final class ViewBuyerIdAction
{
    public function __construct(
        private readonly ViewIdentityDocumentAction $view,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** The buyer's latest verified document, or null. */
    public static function documentFor(string $buyerId): ?IdentityDocument
    {
        return IdentityDocument::query()
            ->where('customer_id', $buyerId)
            ->where('status', IdentityDocumentStatus::VERIFIED->value)
            ->whereNull('image_deleted_at')
            ->latest('created_at')
            ->first();
    }

    /** @return array{bytes: string, mime: string} */
    public function handle(Staff $actor, string $orderId, string $side, RequestContext $ctx): array
    {
        return DB::transaction(function () use ($actor, $orderId, $side, $ctx) {
            $order = Order::query()->findOrFail($orderId, ['order_id', 'order_ref', 'buyer_id', 'state']);
            $collection = OrderCollection::query()->where('order_id', $orderId)->first();
            $document = $order->state === OrderState::READY_TO_COLLECT && $collection !== null && $collection->collected_at === null
                ? self::documentFor($order->buyer_id) : null;
            $ref = $document === null ? null : ($side === ViewIdentityDocumentAction::SIDE_BACK ? $document->back_ref : $document->front_ref);
            if ($ref === null) {
                throw (new ModelNotFoundException)->setModel(IdentityDocument::class, [$orderId]);
            }

            $this->audit->execute(AuditEvent::ORDER_BUYER_ID_VIEWED, 'success',
                ['order_ref' => $order->order_ref, 'side' => $side, 'doc_kind' => $document->doc_kind->value],
                'order', $orderId, $ctx, actorStaffId: $actor->staff_id);

            return $this->view->handle($actor, $document->document_id, $side, $ctx);
        });
    }
}
