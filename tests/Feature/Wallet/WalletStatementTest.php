<?php

use App\Enums\AccountKind;
use App\Enums\AuditEvent;
use App\Enums\LedgerEventKind;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\TopUp;
use App\Support\SystemActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Ledger;

uses(RefreshDatabase::class);

// Spec 008 FR-016 / FR-019 / SC-006: the Wallet statement in three views.

function statement($test, Staff $staff, array $query)
{
    app('auth')->forgetGuards();

    return $test->withToken(staffAccessToken($staff))->getJson('/api/v1/dashboard/wallet-statement?'.http_build_query($query));
}

/** SC-006, on any page: before + in − out = after, and rows chain. */
function assertReconciles(array $summary, array $rows, bool $firstPage = true, bool $lastPage = true): void
{
    $previous = null;
    foreach ($rows as $row) {
        expect(bcsub(bcadd($row['before'], $row['in'], 4), $row['out'], 4))->toBe($row['after']);
        if ($previous !== null) {
            expect($row['before'])->toBe($previous['after']);
        }
        $previous = $row;
    }

    expect(bcsub(bcadd($summary['opening'], $summary['in'], 4), $summary['out'], 4))->toBe($summary['closing']);

    if ($rows !== [] && $firstPage) {
        expect($rows[0]['before'])->toBe($summary['opening']);
    }
    if ($rows !== [] && $lastPage) {
        expect(end($rows)['after'])->toBe($summary['closing']);
    }
}

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->finance = Staff::factory()->role(SeedRole::FINANCE)->create();
    $this->mona = Customer::factory()->verified()->create(['full_name' => 'Mona Hassan Ibrahim']);
    $this->omar = Customer::factory()->verified()->create();

    $bank = Account::internal(AccountKind::BANK);
    $available = Ledger::available($this->mona);
    $held = Ledger::held($this->mona);

    // July: the opening balance for August.
    Ledger::postAt('2026-07-20 10:00', LedgerEventKind::TOPUP, $this->mona, [[$bank, '-23808'], [$available, '23808']]);
    // August.
    Ledger::postAt('2026-08-02 10:12', LedgerEventKind::TOPUP, $this->mona, [[$bank, '-41220'], [$available, '41220']]);
    Ledger::postAt('2026-08-26 19:22', LedgerEventKind::DEPOSIT_HOLD, $this->mona, [[$available, '-24000'], [$held, '24000']]);
    Ledger::postAt('2026-08-27 11:15', LedgerEventKind::DEPOSIT_RELEASE, $this->mona, [[$held, '-24000'], [$available, '24000']]);
    Ledger::postAt('2026-08-28 10:14', LedgerEventKind::COMPENSATION, null, [
        [Account::internal(AccountKind::EXTERNAL_EQUITY), '-800'], [$available, '800'],
    ], staffId: $this->finance->staff_id, memo: 'Wasted trip to IGI');
    Ledger::postAt('2026-08-29 09:12', LedgerEventKind::DEPOSIT_HOLD, $this->mona, [[$available, '-50020'], [$held, '50020']]);
    // 23:30 Cairo on 31 August is still August; 00:30 on 1 September is not.
    Ledger::postAt('2026-08-31 23:30', LedgerEventKind::TOPUP, $this->omar, [[$bank, '-100'], [Ledger::available($this->omar), '100']]);
    Ledger::postAt('2026-09-01 00:30', LedgerEventKind::TOPUP, $this->mona, [[$bank, '-1'], [$available, '1']]);
    // The system actor is never "by hand".
    Ledger::postAt('2026-08-30 03:00', LedgerEventKind::DEPOSIT_RELEASE, null, [[$held, '-20'], [$available, '20']], staffId: SystemActor::id());
    // Dahab's earnings (a made-up settlement slice).
    Ledger::postAt('2026-08-15 12:00', LedgerEventKind::COMMISSION, null, [
        [Account::internal(AccountKind::EXTERNAL_EQUITY), '-684'],
        [Account::internal(AccountKind::DAHAB_COMMISSION), '600'],
        [Account::internal(AccountKind::VAT_PAYABLE), '84'],
    ], staffId: $this->finance->staff_id);
    Ledger::postAt('2026-08-15 12:00', LedgerEventKind::SPREAD, null, [
        [Account::internal(AccountKind::EXTERNAL_EQUITY), '-262.5'], [Account::internal(AccountKind::DAHAB_SPREAD), '262.5'],
    ], staffId: $this->finance->staff_id);

    $this->august = ['from' => '2026-08-01', 'to' => '2026-08-31'];
});

it('reconciles one customer\'s August on available, with holds as outs and held beside', function () {
    $r = statement($this, $this->finance, ['view' => 'customer', 'customer_id' => $this->mona->customer_id] + $this->august)->assertOk();
    $summary = $r->json('data.summary');
    $rows = $r->json('data.rows');

    expect($summary)->toMatchArray([
        'view' => 'customer',
        'opening' => '23808.0000',
        'in' => '66040.0000',   // 41,220 + 24,000 release + 800 + 20 release
        'out' => '74020.0000',  // 24,000 + 50,020 holds
        'closing' => '15828.0000',
        'available' => '15828.0000',
        'held' => '50000.0000',
        'total' => '65828.0000',
    ])->and($summary['customer'])->toMatchArray(['id' => $this->mona->customer_id, 'full_name' => 'Mona Hassan Ibrahim']);

    expect(collect($rows)->pluck('kind')->all())->toBe(['topup', 'deposit_hold', 'deposit_release', 'compensation', 'deposit_hold', 'deposit_release'])
        ->and($rows[1])->toMatchArray(['label' => 'Deposit held', 'in' => '0.0000', 'out' => '24000.0000', 'held_after' => '24000.0000'])
        ->and($rows[3])->toMatchArray(['by_hand' => true, 'memo' => 'Wasted trip to IGI', 'label' => 'Compensation from Dahab'])
        ->and($rows[3]['actor'])->toMatchArray(['type' => 'staff'])
        ->and($rows[5])->toMatchArray(['by_hand' => false])
        ->and($rows[5]['actor']['type'])->toBe('system')
        ->and($rows[0]['by_hand'])->toBeFalse()
        ->and(end($rows)['held_after'])->toBe('50000.0000');

    assertReconciles($summary, $rows);
});

it('reconciles all customer wallets on the total owed, where a hold moves nothing', function () {
    $r = statement($this, $this->finance, ['view' => 'customers'] + $this->august)->assertOk();
    $summary = $r->json('data.summary');
    $rows = $r->json('data.rows');
    $hold = collect($rows)->firstWhere('kind', 'deposit_hold');

    expect($summary)->toMatchArray(['opening' => '23808.0000', 'in' => '42120.0000', 'out' => '0.0000', 'closing' => '65928.0000'])
        ->and($summary)->not->toHaveKey('customer')
        ->and($hold)->toMatchArray(['in' => '0.0000', 'out' => '0.0000', 'moved_to_held' => '24000.0000'])
        ->and($hold['before'])->toBe($hold['after'])
        ->and($hold['wallet'])->toMatchArray(['customer_id' => $this->mona->customer_id])
        ->and(collect($rows)->firstWhere('in', '100.0000')['wallet']['customer_id'])->toBe($this->omar->customer_id);

    assertReconciles($summary, $rows);
});

it('reconciles the Dahab wallet on commission and spread, with VAT beside it', function () {
    $r = statement($this, $this->finance, ['view' => 'dahab'] + $this->august)->assertOk();

    expect($r->json('data.summary'))->toMatchArray(['opening' => '0.0000', 'in' => '862.5000', 'closing' => '862.5000', 'vat_payable' => '84.0000'])
        ->and($r->json('data.rows'))->toHaveCount(2);

    assertReconciles($r->json('data.summary'), $r->json('data.rows'));
});

it('groups by Cairo day and by month, matching the rows they summarise', function () {
    $each = statement($this, $this->finance, ['view' => 'customer', 'customer_id' => $this->mona->customer_id] + $this->august)->json('data.rows');
    $days = statement($this, $this->finance, ['view' => 'customer', 'customer_id' => $this->mona->customer_id, 'grain' => 'day'] + $this->august)->assertOk();
    $month = statement($this, $this->finance, ['view' => 'customer', 'customer_id' => $this->mona->customer_id, 'grain' => 'month', 'from' => '2026-07-01', 'to' => '2026-09-30'])->assertOk();

    expect(collect($days->json('data.rows'))->pluck('period')->all())->toBe(['2026-08-02', '2026-08-26', '2026-08-27', '2026-08-28', '2026-08-29', '2026-08-30'])
        ->and(collect($days->json('data.rows'))->sum('count'))->toBe(count($each))
        ->and(collect($month->json('data.rows'))->map(fn ($r) => [$r['period'], $r['count']])->all())->toBe([['2026-07', 1], ['2026-08', 6], ['2026-09', 1]]);

    assertReconciles($days->json('data.summary'), $days->json('data.rows'));
    assertReconciles($month->json('data.summary'), $month->json('data.rows'));
});

it('pages oldest first with continuous balances', function () {
    $query = ['view' => 'customer', 'customer_id' => $this->mona->customer_id, 'per_page' => 2] + $this->august;
    $rows = [];
    $cursor = null;
    do {
        $page = statement($this, $this->finance, $query + ($cursor ? ['cursor' => $cursor] : []))->assertOk();
        array_push($rows, ...$page->json('data.rows'));
        $cursor = $page->json('meta.next_cursor');
    } while ($cursor !== null);

    expect($rows)->toHaveCount(6);
    assertReconciles($page->json('data.summary'), $rows);
});

it('validates the query', function (array $query) {
    statement($this, $this->finance, $query)->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
})->with([
    'no view' => [['from' => '2026-08-01', 'to' => '2026-08-31']],
    'customer view without a customer' => [['view' => 'customer', 'from' => '2026-08-01', 'to' => '2026-08-31']],
    'a customer on another view' => [['view' => 'customers', 'customer_id' => '01a0e932-511b-72d9-b702-512e081c3000', 'from' => '2026-08-01', 'to' => '2026-08-31']],
    'to before from' => [['view' => 'dahab', 'from' => '2026-08-31', 'to' => '2026-08-01']],
    'more than 366 days' => [['view' => 'dahab', 'from' => '2025-01-01', 'to' => '2026-08-31']],
    'a bad grain' => [['view' => 'dahab', 'from' => '2026-08-01', 'to' => '2026-08-31', 'grain' => 'week']],
    'per_page 201' => [['view' => 'dahab', 'from' => '2026-08-01', 'to' => '2026-08-31', 'per_page' => 201]],
    'a tampered cursor' => [['view' => 'dahab', 'from' => '2026-08-01', 'to' => '2026-08-31', 'cursor' => 'eyJwIjoiMjAyNi0wOCJ9']],
]);

it('answers 404 for an unknown customer', function () {
    statement($this, $this->finance, ['view' => 'customer', 'customer_id' => '01a0e932-511b-72d9-b702-512e081c3000'] + $this->august)
        ->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('audits the first page of a one-customer statement only', function () {
    $query = ['view' => 'customer', 'customer_id' => $this->mona->customer_id, 'per_page' => 2] + $this->august;
    $first = statement($this, $this->finance, $query);
    statement($this, $this->finance, $query + ['cursor' => $first->json('meta.next_cursor')])->assertOk();
    statement($this, $this->finance, ['view' => 'customers'] + $this->august)->assertOk();
    statement($this, $this->finance, ['view' => 'dahab'] + $this->august)->assertOk();

    $audits = AuditLog::query()->where('action', AuditEvent::LEDGER_STATEMENT_VIEWED->value)->get();

    expect($audits)->toHaveCount(1)
        ->and($audits[0]->entity_id)->toBe($this->mona->customer_id)
        ->and($audits[0]->actor_staff_id)->toBe($this->finance->staff_id)
        ->and($audits[0]->after_json)->toMatchArray(['view' => 'customer', 'from' => '2026-08-01', 'to' => '2026-08-31']);
});

it('shows a credited top-up with its number, method and how it was matched (spec 009 FR-021)', function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $finance = Staff::factory()->role(SeedRole::FINANCE)->create();
    $customer = Customer::factory()->verified()->create();
    $matched = TopUp::factory()->create(['customer_id' => $customer->customer_id]);
    app('auth')->forgetGuards();
    $this->withToken(staffAccessToken($finance))
        ->postJson("/api/v1/dashboard/topups/{$matched->topup_id}/match", ['amount' => '20000', 'receiving_account_id' => $matched->notice_account_id], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertOk();

    $today = now('Africa/Cairo')->toDateString();
    $res = statement($this, $finance, ['view' => 'customer', 'customer_id' => $customer->customer_id, 'from' => $today, 'to' => $today, 'grain' => 'each'])->assertOk();

    $row = $res->json('data.rows.0');
    expect($row['kind'])->toBe('topup')
        ->and($row['reference'])->toBe('TOP-'.$matched->topup_no)
        ->and($row['memo'])->toBe("Top-up TOP-{$matched->topup_no} · InstaPay · matched from notice {$matched->reference}")
        ->and($row['in'])->toBe('20000.0000');
});
