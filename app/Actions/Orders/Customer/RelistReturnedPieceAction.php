<?php

namespace App\Actions\Orders\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Actions\Orders\Concerns\MovesOrder;
use App\Actions\Orders\Concerns\TellsOrderParties;
use App\Enums\AuditEvent;
use App\Enums\ListingState;
use App\Enums\PieceCategory;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SellerReturn;
use App\Support\DatabaseActor;
use App\Support\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The seller puts a returned piece back on the market instead of collecting
 * it (spec 012 FR-019, research R13; Part 3 §10.1). Trade gate. The listing
 * moves `awaiting_seller_return → live` with an empty line; after an
 * inspection a gold piece takes the IGI-measured karat and weight, recorded
 * in the history note. The return is closed (`relisted_at`) and buyers who
 * asked to be told when the piece is free again are told.
 */
final class RelistReturnedPieceAction
{
    use MovesListing, MovesOrder, TellsOrderParties;

    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Customer $seller, string $orderId, ?RequestContext $ctx = null): Order
    {
        $order = Order::query()->findOrFail($orderId);
        if ($order->seller_id !== $seller->customer_id) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
        }

        return DatabaseActor::order(fn () => DB::transaction(function () use ($seller, $order, $ctx) {
            $listing = $this->lockListing($order->listing_id);
            $order = $this->lockOrder($order->order_id);
            $return = SellerReturn::query()->where('order_id', $order->order_id)->lockForUpdate()->first();

            if ($return === null || ! $return->isOpen() || $listing->state !== ListingState::AWAITING_SELLER_RETURN) {
                throw DomainApiException::illegalListingTransition();
            }

            $note = 'relisted_after_return';
            $result = $order->latestInspection();
            if ($result !== null && $listing->category !== PieceCategory::DIAMOND && $result->measured_weight_g !== null) {
                $old = "{$listing->karat_code}K {$listing->stated_weight_g} g";
                $listing->forceFill([
                    'karat_code' => $result->measured_karat,
                    'stated_weight_g' => (string) $result->measured_weight_g,
                ])->save();
                $note .= ": {$old} -> {$result->measured_karat}K {$result->measured_weight_g} g (IGI)";
            }

            $listing = $this->moveListing($listing, ListingState::LIVE, $seller, null, $note);
            $return->forceFill(['relisted_at' => CarbonImmutable::now()])->save();
            $this->orderFreeAgain[] = $listing->listing_id;

            $this->audit->execute(
                AuditEvent::ORDER_RELISTED,
                'success',
                ['order_ref' => $order->order_ref, 'listing_id' => $listing->listing_id, 'listing_state' => ListingState::LIVE->value,
                    'karat_code' => $listing->karat_code, 'stated_weight_g' => $listing->stated_weight_g === null ? null : (string) $listing->stated_weight_g],
                'order',
                $order->order_id,
                $ctx,
                actorCustomerId: $seller->customer_id,
                before: ['listing_state' => ListingState::AWAITING_SELLER_RETURN->value],
            );

            $this->flushOrderOutbox();

            return $order;
        }));
    }
}
