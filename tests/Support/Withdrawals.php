<?php

namespace Tests\Support;

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\LegalDocument;
use App\Models\PayoutAccount;
use App\Models\Staff;
use App\Models\Withdrawal;
use App\Notifications\WithdrawalConfirmationNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared fixtures for the spec 013 tests. Every state is reached through the
 * HTTP boundary (or the Actions behind it), so the ledger, the history and
 * the pause are always the real ones. Tests using `confirmedFor()` must call
 * Notification::fake() first: the email link's token is read from the faked
 * notification, exactly as a customer would read it from their inbox.
 */
final class Withdrawals
{
    public const CUSTOMER = '/api/v1/customer/me';

    public const STAFF = '/api/v1/dashboard';

    public const PUBLIC = '/api/v1/withdrawal-confirmations';

    /** A verified customer with `$amount` EGP available. */
    public static function funded(string $amount = '60000', array $attributes = []): Customer
    {
        return BuyRequests::funded($amount, $attributes);
    }

    /** A valid Egyptian IBAN (EG + 2 check digits + 25 digits) built from `$seed`. */
    public static function iban(string $seed = '0019000500000000263180002'): string
    {
        $bban = str_pad(substr(preg_replace('/\D/', '', $seed), 0, 25), 25, '0', STR_PAD_LEFT);
        $check = 98 - (int) bcmod($bban.'1416'.'00', '97');

        return 'EG'.str_pad((string) $check, 2, '0', STR_PAD_LEFT).$bban;
    }

    public static function declarationId(): int
    {
        return (int) LegalDocument::current(LegalDocument::PAYOUT_ACCOUNT_DECLARATION)->legal_doc_id;
    }

    /** @param  array<string, mixed>  $overrides */
    public static function add(TestCase $test, Customer $customer, array $overrides = [], ?string $key = null): TestResponse
    {
        return Listings::as($test, $customer)->postJson(self::CUSTOMER.'/payout-accounts', $overrides + [
            'bank_name' => 'CIB',
            'account_name' => 'Mona Hassan Ibrahim',
            'account_number_or_iban' => self::iban(),
            'declaration_id' => self::declarationId(),
            'declaration_accepted' => true,
        ], Listings::key($key));
    }

    public static function staff(TestCase $test, SeedRole $role): Staff
    {
        return TopUps::actAsStaff($test, $role);
    }

    public static function verify(TestCase $test, string $accountId, ?string $key = null): TestResponse
    {
        self::staff($test, SeedRole::VERIFICATION);

        return $test->postJson(self::STAFF."/payout-accounts/{$accountId}/verify", [], Listings::key($key));
    }

    /** Add an account through the API and have Verification verify it. */
    public static function verifiedAccount(TestCase $test, Customer $customer, array $overrides = []): PayoutAccount
    {
        self::add($test, $customer, $overrides)->assertCreated();
        // created_at is the same for every row of one test transaction: find the one under review.
        $id = PayoutAccount::query()->where('customer_id', $customer->customer_id)->where('state', 'pending_review')->sole()->payout_account_id;
        self::verify($test, $id)->assertOk();

        return PayoutAccount::query()->findOrFail($id);
    }

    public static function requestConfirmation(TestCase $test, Customer $customer, string $amount, string $accountId, ?string $key = null): TestResponse
    {
        return Listings::as($test, $customer)->postJson(self::CUSTOMER.'/withdrawals/confirmations',
            ['amount' => $amount, 'payout_account_id' => $accountId], Listings::key($key));
    }

    /** The token in the latest confirmation email sent to `$customer`. */
    public static function emailedToken(Customer $customer): string
    {
        $sent = Notification::sent($customer, WithdrawalConfirmationNotification::class)->last();

        // The link is a hash route (…/#/withdraw-confirm?token=…): the query sits in the fragment.
        return (string) substr($sent->link, strpos($sent->link, 'token=') + 6);
    }

    public static function confirmLink(TestCase $test, string $token): TestResponse
    {
        Listings::anonymous($test);

        return $test->postJson(self::PUBLIC.'/confirm', ['token' => $token]);
    }

    /** Ask for the link, open it and confirm: the confirmation id, ready to submit. */
    public static function confirmedFor(TestCase $test, Customer $customer, string $amount, string $accountId): string
    {
        $id = self::requestConfirmation($test, $customer, $amount, $accountId)->assertCreated()->json('data.id');
        self::confirmLink($test, self::emailedToken($customer))->assertOk();

        return $id;
    }

    public static function submit(TestCase $test, Customer $customer, string $confirmationId, string $amount, string $accountId, ?string $key = null): TestResponse
    {
        return Listings::as($test, $customer)->postJson(self::CUSTOMER.'/withdrawals',
            ['confirmation_id' => $confirmationId, 'amount' => $amount, 'payout_account_id' => $accountId], Listings::key($key));
    }

    /** A customer with money, a verified account in use and a `requested` withdrawal of `$amount`. */
    public static function requested(TestCase $test, string $amount = '42000', string $funds = '56760', ?Customer $customer = null): Withdrawal
    {
        $customer ??= self::funded($funds);
        $account = PayoutAccount::query()->where('customer_id', $customer->customer_id)->where('is_in_use', true)->first()
            ?? self::verifiedAccount($test, $customer);
        $confirmation = self::confirmedFor($test, $customer, $amount, $account->payout_account_id);
        $id = self::submit($test, $customer, $confirmation, $amount, $account->payout_account_id)->assertCreated()->json('data.id');

        return Withdrawal::query()->findOrFail($id);
    }

    public static function act(TestCase $test, Withdrawal $withdrawal, string $action, array $body = [], ?string $key = null): TestResponse
    {
        return $test->postJson(self::STAFF."/withdrawals/{$withdrawal->withdrawal_id}/{$action}", $body, Listings::key($key));
    }

    /** Taken for review by Finance. */
    public static function underReview(TestCase $test, ?Withdrawal $withdrawal = null): Withdrawal
    {
        $withdrawal ??= self::requested($test);
        self::staff($test, SeedRole::FINANCE);
        self::act($test, $withdrawal, 'review')->assertOk();

        return $withdrawal->refresh();
    }

    public static function release(TestCase $test, Withdrawal $withdrawal, string $bankTxn = 'FT2610031234', ?string $key = null): TestResponse
    {
        return self::act($test, $withdrawal, 'release', ['bank_txn_number' => $bankTxn, 'transfer_reference' => $withdrawal->number(), 'value_date' => now()->toDateString()], $key);
    }

    public static function customer(Withdrawal|PayoutAccount $row): Customer
    {
        return Customer::query()->findOrFail($row->customer_id);
    }

    public static function sweep(): int
    {
        return Artisan::call('withdrawals:sweep');
    }

    /** @return list<array{0: string, 1: string}> [account kind, amount] of a ledger transaction */
    public static function lines(?string $txnId): array
    {
        return DB::table('ledger_posting AS p')->join('account AS a', 'a.account_id', '=', 'p.account_id')
            ->where('p.ledger_txn_id', $txnId)->orderBy('p.posting_id')
            ->get(['a.kind', 'p.amount'])->map(fn ($l) => [$l->kind, (string) $l->amount])->all();
    }
}
