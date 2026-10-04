<?php

use App\Enums\AccountKind;
use App\Enums\LedgerEventKind;
use App\Enums\SeedRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DailyClose;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Finance;
use Tests\Support\Ledger;

uses(RefreshDatabase::class);

// Spec 015 FR-012–FR-015 (Clarifications): close an ended Cairo day against the
// typed statement balance; the books are the ledger at midnight; 0 locks, a
// non-zero difference locks only with an explanation, else the day is saved;
// a locked day never changes.

/** A top-up of `$amount` at a Cairo time (entries in a test share one clock, so dated entries are written by hand). */
function topUpAt(string $when, Customer $customer, string $amount): void
{
    Ledger::postAt($when, LedgerEventKind::TOPUP, $customer, [[Account::internal(AccountKind::BANK), '-'.$amount], [Ledger::available($customer), $amount]]);
}

beforeEach(function () {
    $this->yesterday = now('Africa/Cairo')->subDay()->toDateString();
    $this->customer = Customer::factory()->verified()->create();
    topUpAt($this->yesterday.' 10:00:00', $this->customer, '3000');
    topUpAt($this->yesterday.' 23:59:00', $this->customer, '200');
    topUpAt(now('Africa/Cairo')->toDateString().' 00:00:30', $this->customer, '50'); // after the cut-off
});

it('shows a past day at its midnight cut-off and today live, without Close', function () {
    Finance::staff($this, SeedRole::FINANCE);

    $this->getJson('/api/v1/dashboard/daily-close?date='.$this->yesterday)->assertOk()
        ->assertJsonPath('data.ended', true)->assertJsonPath('data.can_close', true)->assertJsonPath('data.close', null)
        ->assertJsonPath('data.books.bank', '3200.0000')->assertJsonPath('data.books.customer_available', '3200.0000')
        ->assertJsonPath('data.books.customer_held', '0.0000')->assertJsonPath('data.books.customer_liability', '3200.0000')
        ->assertJsonPath('data.books.dahab_wallet', '0.0000');
    $this->getJson('/api/v1/dashboard/daily-close')->assertOk()
        ->assertJsonPath('data.date', now('Africa/Cairo')->toDateString())->assertJsonPath('data.ended', false)
        ->assertJsonPath('data.can_close', false)->assertJsonPath('data.books.bank', '3250.0000');
});

it('locks a clean day, audited, and never closes it again', function () {
    $finance = Finance::staff($this, SeedRole::FINANCE);

    Finance::close($this, $this->yesterday, '3200')->assertOk()
        ->assertJsonPath('data.state', 'locked')->assertJsonPath('data.close.is_locked', true)
        ->assertJsonPath('data.close.difference', '0.0000')->assertJsonPath('data.close.closed_by.id', $finance->staff_id);
    expect(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'day.closed')->sole()->actor_staff_id))->toBe($finance->staff_id);

    Finance::close($this, $this->yesterday, '3200')->assertStatus(409)->assertJsonPath('code', 'day_already_closed');
    Finance::close($this, $this->yesterday, '9999', 'Trying to change a locked day.')->assertStatus(409);
    expect(DatabaseActor::elevate('maintenance', fn () => DailyClose::query()->sole()->bank_balance))->toBe('3200.0000');
});

it('saves a day with a difference unlocked, then locks it with an explanation', function () {
    Finance::staff($this, SeedRole::FINANCE);

    Finance::close($this, $this->yesterday, '2950')->assertOk()->assertJsonPath('data.state', 'saved')
        ->assertJsonPath('data.close.difference', '-250.0000')->assertJsonPath('data.close.is_locked', false);
    expect(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'day.saved')->count()))->toBe(1);

    Finance::close($this, $this->yesterday, '2950', 'short')->assertStatus(422)->assertJsonValidationErrors('explanation');
    Finance::close($this, $this->yesterday, '2950', 'Bank charge, recorded after.')->assertOk()
        ->assertJsonPath('data.state', 'locked')->assertJsonPath('data.close.explanation', 'Bank charge, recorded after.')
        ->assertJsonPath('data.close.difference', '-250.0000');
});

it('keeps a closed day the same whatever is posted later', function () {
    Finance::staff($this, SeedRole::FINANCE);
    Finance::close($this, $this->yesterday, '3200')->assertOk();

    Finance::record($this, 'bank_charge', 'out', '250', ['occurred_on' => $this->yesterday])->assertCreated();
    $this->getJson('/api/v1/dashboard/daily-close?date='.$this->yesterday)->assertOk()
        ->assertJsonPath('data.close.bank_balance', '3200.0000')->assertJsonPath('data.close.books_bank', '3200.0000')
        ->assertJsonPath('data.can_close', false);
});

it('counts the movements recorded by hand for the day', function () {
    Finance::staff($this, SeedRole::FINANCE);
    Finance::record($this, 'capital_in', 'in', '1000', ['occurred_on' => $this->yesterday])->assertCreated();
    Finance::record($this, 'bank_charge', 'out', '40', ['occurred_on' => $this->yesterday])->assertCreated();
    Finance::record($this, 'own_transfer', 'out', '500', ['occurred_on' => $this->yesterday])->assertCreated();

    $this->getJson('/api/v1/dashboard/daily-close?date='.$this->yesterday)->assertOk()
        ->assertJsonPath('data.books.movements_in', '1000.0000')->assertJsonPath('data.books.movements_out', '40.0000');
});

it('refuses today and the future, and validates', function () {
    Finance::staff($this, SeedRole::FINANCE);

    Finance::close($this, now('Africa/Cairo')->toDateString(), '3250')->assertStatus(422)->assertJsonPath('code', 'day_not_ended');
    Finance::close($this, now('Africa/Cairo')->addDay()->toDateString(), '3250')->assertStatus(422)->assertJsonPath('code', 'day_not_ended');
    Finance::close($this, 'yesterday', '3250')->assertStatus(422)->assertJsonValidationErrors('date');
    Finance::close($this, $this->yesterday, 'lots')->assertStatus(422)->assertJsonValidationErrors('bank_balance');
});

it('lists the recent days with the month figures', function () {
    Finance::staff($this, SeedRole::FINANCE);
    $twoAgo = now('Africa/Cairo')->subDays(2)->toDateString();
    Finance::close($this, $twoAgo, '0')->assertOk();
    Finance::close($this, $this->yesterday, '2950', 'Bank charge, recorded after.')->assertOk();

    $res = $this->getJson('/api/v1/dashboard/daily-closes?from='.now('Africa/Cairo')->subDays(5)->toDateString())->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('data.0.date', $this->yesterday)
        ->assertJsonPath('meta.days_closed', 2)
        ->assertJsonPath('meta.last_difference.date', $this->yesterday)->assertJsonPath('meta.last_difference.difference', '-250.0000');
    expect($res->json('meta.days_in_period'))->toBe(5);
});

it('lets wallet.view read and only day.close close; never the COO; replays a key once', function () {
    $viewer = Finance::staff($this, SeedRole::OPERATIONS);
    $viewer->givePermissionTo('wallet.view');
    $this->getJson('/api/v1/dashboard/daily-close?date='.$this->yesterday)->assertOk()->assertJsonPath('data.can_close', false);
    Finance::close($this, $this->yesterday, '3200')->assertForbidden();

    Finance::staff($this, SeedRole::COO);
    $this->getJson('/api/v1/dashboard/daily-closes')->assertForbidden();

    Finance::staff($this, SeedRole::FINANCE);
    $key = (string) Str::uuid();
    Finance::close($this, $this->yesterday, '3200', key: $key)->assertOk();
    Finance::close($this, $this->yesterday, '3200', key: $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
});
