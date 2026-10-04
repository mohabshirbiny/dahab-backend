<?php

namespace Tests\Support;

use App\Enums\AccountKind;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Staff;
use App\Support\DatabaseActor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared fixtures for the spec 015 finance tests. Money always moves through
 * the HTTP boundary (or the Actions behind it); read-backs go to the ledger.
 */
final class Finance
{
    /** A verified customer with money in their available balance (a real top-up entry). */
    public static function customer(string $funds = '1000', array $attributes = []): Customer
    {
        $customer = Customer::factory()->verified()->create($attributes);
        if (bccomp($funds, '0', 4) > 0) {
            Ledger::topUp($customer, $funds);
        }

        return $customer;
    }

    public static function staff(TestCase $test, SeedRole $role): Staff
    {
        return Orders::staff($test, $role);
    }

    /** Act as an existing staff member again. */
    public static function actAs(Staff $staff): Staff
    {
        return Disputes::actAs($staff);
    }

    /** @return array<string, string> */
    public static function key(?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
    }

    public static function available(Customer $customer): string
    {
        return self::sum(Account::forCustomerKind($customer->customer_id, AccountKind::CUST_AVAILABLE));
    }

    public static function equity(): string
    {
        return self::sum(Account::internal(AccountKind::EXTERNAL_EQUITY));
    }

    /** The bank's cash: −SUM(bank) (spec 008 R15). */
    public static function bankCash(): string
    {
        return bcmul(self::sum(Account::internal(AccountKind::BANK)), '-1', 4);
    }

    public static function globalSum(): string
    {
        return DatabaseActor::elevate('maintenance', fn () => (string) DB::selectOne('SELECT must_be_zero::numeric(18,4)::text AS z FROM ledger_global_zero')->z);
    }

    public static function pay(TestCase $test, Customer $customer, string $amount, array $overrides = [], ?string $key = null): TestResponse
    {
        return $test->postJson('/api/v1/dashboard/compensation', array_merge([
            'customer_id' => $customer->customer_id, 'amount' => $amount, 'reason' => 'wasted_trip',
            'note' => 'They came to the branch for nothing.',
        ], $overrides), self::key($key));
    }

    public static function adjust(TestCase $test, Customer $customer, string $direction, string $amount, array $overrides = [], ?string $key = null): TestResponse
    {
        return $test->postJson("/api/v1/dashboard/customers/{$customer->customer_id}/wallet-adjustments", array_merge([
            'direction' => $direction, 'amount' => $amount, 'reason' => 'Correcting a top-up entered twice.',
        ], $overrides), self::key($key));
    }

    public static function proofToken(TestCase $test, ?UploadedFile $file = null): string
    {
        return (string) $test->post('/api/v1/dashboard/uploads', ['purpose' => 'bank_movement_proof', 'file' => $file ?? Listings::pdf('advice.pdf')],
            ['Accept' => 'application/json'])->assertCreated()->json('data.token');
    }

    public static function record(TestCase $test, string $kind, string $direction, string $amount, array $overrides = [], ?string $key = null): TestResponse
    {
        return $test->postJson('/api/v1/dashboard/bank-movements', array_merge([
            'kind' => $kind, 'direction' => $direction, 'amount' => $amount,
            'occurred_on' => now('Africa/Cairo')->toDateString(), 'reason' => 'Recorded from the bank advice.',
        ], $overrides), self::key($key));
    }

    public static function close(TestCase $test, string $date, string $bankBalance, ?string $explanation = null, ?string $key = null): TestResponse
    {
        return $test->postJson('/api/v1/dashboard/daily-close', array_filter([
            'date' => $date, 'bank_balance' => $bankBalance, 'explanation' => $explanation,
        ], fn ($v) => $v !== null), self::key($key));
    }

    private static function sum(string $accountId): string
    {
        return DatabaseActor::elevate('maintenance', fn () => (string) DB::table('ledger_posting')->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,4)::text AS s')->value('s'));
    }
}
