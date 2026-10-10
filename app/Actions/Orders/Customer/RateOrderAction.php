<?php

namespace App\Actions\Orders\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Enums\AuditEvent;
use App\Enums\PartyRole;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderRating;
use App\Support\DatabaseActor;
use App\Support\Orders\RatingWindow;
use App\Support\RequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * A party rates an order: 1–5 stars and an optional note about the
 * experience with Dahab (spec 018 US4, FR-030–FR-036; research R12). Verified
 * gate — a suspended customer may rate, a closed one is refused at the gate.
 * One immutable row per party and order, written in the author's own `order`
 * scope; the seller from the moment the piece is ready to collect, the buyer
 * once it is completed, for thirty days. Nothing else changes and no one is
 * told. Audited with the stars only — the note's text never goes to the log.
 */
final class RateOrderAction
{
    use MovesOrder;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Customer $actor, string $orderId, int $stars, ?string $note, ?RequestContext $ctx = null): OrderRating
    {
        $order = Order::query()->findOrFail($orderId);
        if (! $order->isParty($actor->customer_id)) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
        }

        $role = $order->seller_id === $actor->customer_id ? PartyRole::SELLER : PartyRole::BUYER;
        $note = $note === null || trim($note) === '' ? null : trim($note);

        return DatabaseActor::order(fn () => DB::transaction(function () use ($actor, $order, $role, $stars, $note, $ctx) {
            // The lock serialises two submits of the same party; the loser finds the row.
            $order = $this->lockOrder($order->order_id);

            $window = RatingWindow::of($order, $role);
            if ($window === null) {
                throw DomainApiException::ratingNotAvailable();
            }

            if (OrderRating::query()->where('order_id', $order->order_id)->where('party_role', $role->value)->exists()) {
                throw DomainApiException::alreadyRated();
            }

            if (! $window->isOpen()) {
                throw DomainApiException::ratingClosed();
            }

            $rating = OrderRating::query()->create([
                'order_id' => $order->order_id,
                'party_role' => $role->value,
                'customer_id' => $actor->customer_id,
                'stars' => $stars,
                'note' => $note,
            ]);

            $this->audit->execute(
                AuditEvent::ORDER_RATED,
                'success',
                ['order_ref' => $order->order_ref, 'party_role' => $role->value, 'stars' => $stars, 'has_note' => $note !== null],
                'order',
                $order->order_id,
                $ctx,
                actorCustomerId: $actor->customer_id,
            );

            return $rating->refresh();
        }));
    }
}
