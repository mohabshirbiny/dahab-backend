<?php

namespace App\Actions\Orders;

use App\Actions\Listings\Concerns\MovesListing;
use App\Enums\ListingState;
use App\Models\Customer;
use App\Models\LedgerTransaction;
use App\Models\Listing;
use App\Models\Order;
use App\Models\SellerReturn;
use App\Models\Staff;
use App\Support\Orders\CollectionCodes;
use App\Support\Orders\DeadlinePolicy;

/**
 * The piece goes back to its seller (spec 012 FR-012, FR-013, FR-018,
 * research R13): the listing moves to `awaiting_seller_return` and a
 * `seller_return` opens at the order's branch with a code for the seller and
 * a calendar return window. After a no-pay it carries the forfeit
 * compensation transaction; after an inspection cancel or a declined
 * adjustment it carries none. Inside the caller's transaction, listing locked.
 */
final class OpenSellerReturnAction
{
    use MovesListing;

    public function __construct(
        private readonly CollectionCodes $codes,
        private readonly DeadlinePolicy $deadlines,
    ) {}

    /** @return array{return: SellerReturn, code: string} */
    public function handle(Order $order, Listing $listing, ?LedgerTransaction $compensation, ?Customer $byCustomer, ?Staff $byStaff, string $note): array
    {
        $this->moveListing($listing, ListingState::AWAITING_SELLER_RETURN, $byCustomer, $byStaff, $note);

        $code = $this->codes->issue();

        $return = SellerReturn::query()->create([
            'order_id' => $order->order_id,
            'listing_id' => $listing->listing_id,
            'seller_id' => $order->seller_id,
            'branch_id' => $order->branch_id,
            'code_hash' => $this->codes->hash($code),
            'code_encrypted' => $code,
            'return_deadline' => $this->deadlines->returnWindow(),
            'compensation_txn_id' => $compensation?->ledger_txn_id,
        ]);

        return ['return' => $return, 'code' => $code];
    }
}
