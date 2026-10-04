<?php

namespace App\Actions\Orders\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Enums\AuditEvent;
use App\Enums\OrderState;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderCollection;
use App\Support\DatabaseActor;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The buyer withdraws the person named to collect (spec 014 FR-018) before
 * the piece is collected: the proxy fields are cleared and only the buyer
 * can collect again. The acceptance stays as evidence. Audited `order` scope;
 * verified gate (removing a proxy never needs the trade gate).
 */
final class RemoveProxyAction
{
    use MovesOrder;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Customer $buyer, string $orderId, ?RequestContext $ctx = null): Order
    {
        $order = Order::query()->findOrFail($orderId);
        if ($order->buyer_id !== $buyer->customer_id) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
        }

        return DatabaseActor::order(fn () => DB::transaction(function () use ($buyer, $order, $ctx) {
            $order = $this->lockOrder($order->order_id);
            $this->assertNotFrozen($order);
            $collection = OrderCollection::query()->where('order_id', $order->order_id)->lockForUpdate()->first();
            if ($order->state !== OrderState::READY_TO_COLLECT || $collection === null
                || $collection->collected_at !== null || ! $collection->is_proxy) {
                throw DomainApiException::illegalOrderTransition();
            }

            $name = $collection->proxy_name;
            $collection->forceFill([
                'is_proxy' => false,
                'proxy_name' => null,
                'proxy_phone' => null,
                'proxy_id_storage_ref' => null,
                'proxy_acceptance_id' => null,
                'proxy_named_at' => null,
            ])->save();

            $this->audit->execute(AuditEvent::ORDER_PROXY_REMOVED, 'success',
                ['order_ref' => $order->order_ref, 'proxy_name' => $name],
                'order', $order->order_id, $ctx, actorCustomerId: $buyer->customer_id);

            return $order;
        }));
    }
}
