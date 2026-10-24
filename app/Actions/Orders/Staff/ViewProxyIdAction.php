<?php

namespace App\Actions\Orders\Staff;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Order;
use App\Models\OrderCollection;
use App\Models\Staff;
use App\Services\IdentityDocumentStorage;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Staff open the ID photo of the person named to collect (spec 014 FR-020,
 * research R14): one side — the front (or passport photo page) or the back —
 * decrypted, never cached, one `order.proxy_id_viewed` audit row per view.
 * 404 when no proxy is named or that side was not uploaded.
 */
final class ViewProxyIdAction
{
    public function __construct(
        private readonly IdentityDocumentStorage $storage,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return array{bytes: string, mime: string} */
    public function handle(Staff $actor, string $orderId, ?RequestContext $ctx = null, string $side = 'front'): array
    {
        return DB::transaction(function () use ($actor, $orderId, $ctx, $side) {
            $order = Order::query()->findOrFail($orderId, ['order_id', 'order_ref']);
            $collection = OrderCollection::query()->where('order_id', $orderId)->first();
            $ref = $collection !== null && $collection->is_proxy ? $collection->proxyIdRef($side) : null;
            if ($ref === null) {
                throw (new ModelNotFoundException)->setModel(OrderCollection::class, [$orderId]);
            }

            $this->audit->execute(AuditEvent::ORDER_PROXY_ID_VIEWED, 'success',
                ['order_ref' => $order->order_ref, 'proxy_name' => $collection->proxy_name, 'side' => $side],
                'order', $orderId, $ctx, actorStaffId: $actor->staff_id);

            $bytes = $this->storage->read($ref);

            return ['bytes' => $bytes, 'mime' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream'];
        });
    }
}
