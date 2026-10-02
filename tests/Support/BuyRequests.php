<?php

namespace Tests\Support;

use App\Enums\AccountKind;
use App\Models\Account;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\LegalDocument;
use App\Models\Listing;
use App\Support\DatabaseActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared fixtures for the spec 011 buy-request tests. Requests are always
 * created through the API (the real hold on the ledger, the real position),
 * never written directly: the database refuses a request without its hold.
 */
final class BuyRequests
{
    public const BUYER_URL = '/api/v1/customer/me/buy-requests';

    public const SELLER_URL = '/api/v1/customer/me/listings';

    public const ORDERS_URL = '/api/v1/dashboard/orders';

    /** A verified buyer with `$amount` EGP available. */
    public static function funded(string $amount = '50000', array $attributes = []): Customer
    {
        $buyer = Customer::factory()->verified()->create($attributes);
        if (bccomp($amount, '0', 4) > 0) {
            Ledger::topUp($buyer, $amount);
        }

        return $buyer;
    }

    public static function termsId(): int
    {
        return (int) LegalDocument::current(LegalDocument::DEPOSIT_AGREEMENT)->legal_doc_id;
    }

    /** The price a buyer sees now on the market (current_price). */
    public static function price(TestCase $test, Listing $listing): string
    {
        return (string) Listings::anonymous($test)->getJson(Listings::MARKET_URL."/{$listing->listing_id}")->assertOk()->json('data.current_price');
    }

    /** @param  array<string, mixed>  $overrides */
    public static function send(TestCase $test, Customer $buyer, Listing $listing, array $overrides = [], ?string $key = null): TestResponse
    {
        $body = array_merge([
            'listing_id' => $listing->listing_id,
            'confirm_locked_price' => $overrides['confirm_locked_price'] ?? self::price($test, $listing),
            'deposit_legal_doc_id' => self::termsId(),
        ], $overrides);

        return Listings::as($test, $buyer)->postJson(self::BUYER_URL, $body, Listings::key($key));
    }

    /** Send and return the created request. */
    public static function queued(TestCase $test, Customer $buyer, Listing $listing): BuyRequest
    {
        $id = (string) self::send($test, $buyer, $listing)->assertCreated()->json('data.id');

        return BuyRequest::query()->findOrFail($id);
    }

    public static function leave(TestCase $test, Customer $buyer, BuyRequest|string $request, bool $notify = false, ?string $key = null): TestResponse
    {
        $id = $request instanceof BuyRequest ? $request->buy_request_id : $request;

        return Listings::as($test, $buyer)->postJson(self::BUYER_URL."/{$id}/withdraw", ['notify_when_free' => $notify], Listings::key($key));
    }

    public static function accept(TestCase $test, Customer $seller, Listing $listing, BuyRequest|string $request, int $branchId, ?string $key = null): TestResponse
    {
        $id = $request instanceof BuyRequest ? $request->buy_request_id : $request;

        return Listings::as($test, $seller)->postJson(self::SELLER_URL."/{$listing->listing_id}/accept", ['buy_request_id' => $id, 'branch_id' => $branchId], Listings::key($key));
    }

    public static function decline(TestCase $test, Customer $seller, Listing $listing, BuyRequest|string $request, ?string $key = null): TestResponse
    {
        $id = $request instanceof BuyRequest ? $request->buy_request_id : $request;

        return Listings::as($test, $seller)->postJson(self::SELLER_URL."/{$listing->listing_id}/decline", ['buy_request_id' => $id], Listings::key($key));
    }

    public static function branchOf(Listing $listing): int
    {
        return (int) DB::table('listing_branch_option')->where('listing_id', $listing->listing_id)->value('branch_id');
    }

    /** @return array{available: string, held: string} a customer's balances, straight from the ledger */
    public static function balances(Customer $customer): array
    {
        return DatabaseActor::elevate('maintenance', function () use ($customer) {
            $sum = fn (AccountKind $kind) => bcadd((string) (DB::table('ledger_posting')
                ->where('account_id', Account::forCustomerKind($customer->customer_id, $kind))
                ->sum('amount')), '0', 4);

            return ['available' => $sum(AccountKind::CUST_AVAILABLE), 'held' => $sum(AccountKind::CUST_HELD)];
        });
    }

    /** holds − releases for one request, in EGP. */
    public static function netHeld(BuyRequest|string $request): string
    {
        $id = $request instanceof BuyRequest ? $request->buy_request_id : $request;

        return DatabaseActor::elevate('maintenance', fn () => bcadd((string) DB::table('ledger_transaction as t')
            ->join('ledger_posting as p', 'p.ledger_txn_id', '=', 't.ledger_txn_id')
            ->join('account as a', 'a.account_id', '=', 'p.account_id')
            ->where('t.buy_request_id', $id)->where('a.kind', AccountKind::CUST_HELD->value)
            ->sum('p.amount'), '0', 4));
    }

    /** Fire the deferred checks now, as a commit would (the test runs inside a transaction). */
    public static function checkNow(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }
}
