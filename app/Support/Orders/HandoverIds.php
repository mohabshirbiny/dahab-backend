<?php

namespace App\Support\Orders;

use App\Actions\Orders\Staff\ViewBuyerIdAction;
use App\Enums\OrderState;
use App\Models\Order;
use App\Models\OrderCollection;

/**
 * What the counter may open to check the person collecting a paid piece
 * (docs/features/handover-id-check.md): the buyer's own verified ID while the
 * order waits for collection, and the proxy's ID. Shapes only — the images
 * come through GET /dashboard/orders/{id}/buyer-id and …/proxy-id.
 */
final class HandoverIds
{
    /** @return array{doc_kind: string, has_back: bool}|null */
    public static function buyer(Order $order, ?OrderCollection $collection): ?array
    {
        if ($order->state !== OrderState::READY_TO_COLLECT || $collection === null || $collection->collected_at !== null) {
            return null;
        }
        $document = ViewBuyerIdAction::documentFor($order->buyer_id);

        return $document === null ? null : ['doc_kind' => $document->doc_kind->value, 'has_back' => $document->back_ref !== null];
    }

    /** @return array{id_kind: string|null, has_back: bool} */
    public static function proxy(OrderCollection $collection): array
    {
        return ['id_kind' => $collection->proxy_id_kind?->value, 'has_back' => $collection->proxy_id_back_storage_ref !== null];
    }
}
