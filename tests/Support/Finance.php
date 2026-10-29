<?php

namespace Tests\Support;

use App\Enums\AccountKind;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\Customer;
use App\Models\GoldPrice;
use App\Models\Order;
use App\Models\ReceivingAccount;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
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
            'order_id' => array_key_exists('order_id', $overrides) ? null : self::orderOf($test, $customer)->order_id,
        ], $overrides), self::key($key));
    }

    /** @var array<string, string> one order per customer, so a replay sends the same body */
    private static array $orders = [];

    /**
     * An order of the customer (as the seller, so their wallet is untouched) for the order every
     * compensation names (app notes 2026-10-26). The staff member acting before acts again after.
     */
    public static function orderOf(TestCase $test, Customer $customer): Order
    {
        if (isset(self::$orders[$customer->customer_id]) && ($order = Order::query()->find(self::$orders[$customer->customer_id])) !== null) {
            return $order;
        }
        $staff = app('auth')->guard('staff')->user();
        if (! GoldPrice::query()->exists()) {
            Orders::workedPrices();
        }
        $order = Orders::accepted($test, Orders::ring($customer));
        if ($staff instanceof Staff) {
            Disputes::actAs($staff);
        } else {
            app('auth')->forgetGuards();
        }
        self::$orders[$customer->customer_id] = $order->order_id;

        return $order;
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

    /** Dahab's bank account the tests move money through (owner notes of 2026-10-29: every movement names one). */
    public static function bank(): ReceivingAccount
    {
        return ReceivingAccount::query()->where('label', 'Finance test bank')->first()
            ?? ReceivingAccount::factory()->bankTransfer()->create(['label' => 'Finance test bank', 'sort_order' => -1]);
    }

    public static function record(TestCase $test, string $kind, string $direction, string $amount, array $overrides = [], ?string $key = null): TestResponse
    {
        $bank = $kind === 'own_transfer'
            ? ['receiving_account_id' => self::bank()->receiving_account_id, 'to_receiving_account_id' => ReceivingAccount::factory()->create()->receiving_account_id]
            : ['receiving_account_id' => self::bank()->receiving_account_id];

        return $test->postJson('/api/v1/dashboard/bank-movements', array_merge([
            'kind' => $kind, 'direction' => $direction, 'amount' => $amount,
            'occurred_on' => now('Africa/Cairo')->toDateString(), 'reason' => 'Recorded from the bank advice.',
        ], $bank, $overrides), self::key($key));
    }

    /**
     * Close a day with `$bankBalance` across all accounts: every account the day asks for is typed
     * at its own books and the test bank carries the difference, so the total behaves as before.
     */
    public static function close(TestCase $test, string $date, string $bankBalance, ?string $explanation = null, ?string $key = null): TestResponse
    {
        if (preg_match('/^-?\d{1,14}(\.\d{1,4})?$/', $bankBalance) !== 1) {
            return self::closeWith($test, $date, [['receiving_account_id' => self::bank()->receiving_account_id, 'balance' => $bankBalance]], $explanation, $key);
        }
        $bankId = self::bank()->receiving_account_id;
        $day = $test->getJson('/api/v1/dashboard/daily-close?date='.$date)->json('data');
        // Money the tests post straight to the ledger names no account: assign it to the test bank on that date.
        if ($day !== null && bccomp($day['not_assigned'] ?? '0', '0', 4) > 0 && ! ($day['close']['is_locked'] ?? false)) {
            DatabaseActor::elevate('maintenance', fn () => DB::table('bank_movement')->insert([
                'kind' => 'own_transfer', 'amount' => '-'.$day['not_assigned'], 'occurred_on' => $date,
                'reason' => 'Assigning the money from before banks were named.', 'recorded_by' => SystemActor::id(),
                'receiving_account_id' => null, 'to_receiving_account_id' => $bankId,
            ]));
            $day = $test->getJson('/api/v1/dashboard/daily-close?date='.$date)->json('data');
        }
        $lines = [];
        $books = '0';
        foreach ($day['banks'] ?? [] as $b) {
            $books = bcadd($books, $b['books'], 4);
            $lines[$b['receiving_account_id']] = $b['books'];
        }
        $books = bcadd($books, $day['not_assigned'] ?? '0', 4);
        $lines[$bankId] = bcadd($lines[$bankId] ?? '0', bcsub($bankBalance, $books, 4), 4);

        return self::closeWith($test, $date, array_map(fn ($id, $balance) => ['receiving_account_id' => $id, 'balance' => $balance],
            array_keys($lines), array_values($lines)), $explanation, $key);
    }

    /** @param  list<array{receiving_account_id: int, balance: string}>  $balances */
    public static function closeWith(TestCase $test, string $date, array $balances, ?string $explanation = null, ?string $key = null): TestResponse
    {
        return $test->postJson('/api/v1/dashboard/daily-close', array_filter([
            'date' => $date, 'bank_balances' => $balances, 'explanation' => $explanation,
        ], fn ($v) => $v !== null), self::key($key));
    }

    private static function sum(string $accountId): string
    {
        return DatabaseActor::elevate('maintenance', fn () => (string) DB::table('ledger_posting')->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,4)::text AS s')->value('s'));
    }
}
