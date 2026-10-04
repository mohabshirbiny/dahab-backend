<?php

use App\Enums\AccountKind;
use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Compensation;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Disputes;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 FR-015, research R7, R8: compensation inside a resolution — a
// balanced `compensation` entry from external equity, capped per payment and
// per Cairo day unless compensation.uncapped; all or nothing.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

function compensationBody(string $party, string $amount, string $reason = 'dahab_mistake'): array
{
    return ['compensation' => ['party' => $party, 'amount' => $amount, 'reason' => $reason, 'note' => 'The inspection was delayed on our side.']];
}

/** A fresh frozen order and its dispute. */
function frozenDispute($test): array
{
    $order = Disputes::awaitingBalance($test);

    return [$order, Disputes::opened($test, $order)];
}

it('pays a party within the caps: one balanced entry, its row, the wallet, the audit and the message', function (string $party) {
    Bus::fake([NotifyCustomerJob::class]);
    [$order, $dispute] = frozenDispute($this);
    $customer = $party === 'buyer' ? Orders::buyer($order) : Orders::seller($order);
    $before = BuyRequests::balances($customer)['available'];
    $equity = Orders::internal(AccountKind::EXTERNAL_EQUITY);

    $finance = Orders::staff($this, SeedRole::FINANCE);
    Disputes::resolve($this, $dispute, compensationBody($party, '1500'))->assertOk()
        ->assertJsonPath('data.compensations.0.party', $party)->assertJsonPath('data.compensations.0.amount', '1500.0000');

    $row = DatabaseActor::elevate('maintenance', fn () => Compensation::query()->sole());
    $lines = Orders::lines($order, 'compensation');
    expect(BuyRequests::balances($customer)['available'])->toBe(bcadd($before, '1500', 4))
        ->and(Orders::internal(AccountKind::EXTERNAL_EQUITY))->toBe(bcsub($equity, '1500', 4))
        ->and($row->paid_by)->toBe($finance->staff_id)
        ->and($row->customer_id)->toBe($customer->customer_id)
        ->and($lines)->toHaveCount(2)
        ->and(bcadd($lines[0]->amount, $lines[1]->amount, 4))->toBe('0.0000')
        ->and(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'compensation.paid')->sole()->actor_staff_id))->toBe($finance->staff_id);
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($j) => $j->customerId === $customer->customer_id && $j->notification->event === OrderEvent::COMPENSATION_PAID);
})->with(['buyer', 'seller']);

it('holds Finance to the per-payment cap and leaves the dispute open', function () {
    [$order, $dispute] = frozenDispute($this);
    Orders::staff($this, SeedRole::FINANCE);

    Disputes::resolve($this, $dispute, compensationBody('buyer', '2000.0001'))->assertStatus(403)
        ->assertJsonPath('code', 'compensation_cap_exceeded')
        ->assertJsonPath('details.per_payment', '2000.0000')->assertJsonPath('details.left_today', '5000.0000');
    expect(Disputes::of($order)->state->value)->toBe('open')
        ->and($order->refresh()->state->value)->toBe('disputed')
        ->and(DatabaseActor::elevate('maintenance', fn () => Compensation::query()->count()))->toBe(0);

    Disputes::resolve($this, $dispute, compensationBody('buyer', '2000'))->assertOk();
});

it('holds Finance to the daily cap per staff member, per Cairo day, read live from settings', function () {
    $finance = Orders::staff($this, SeedRole::FINANCE);
    foreach (['1500', '2000', '1500'] as $amount) {
        [, $dispute] = frozenDispute($this);
        Disputes::actAs($finance);
        Disputes::resolve($this, $dispute, compensationBody('buyer', $amount))->assertOk();
    }

    [, $dispute] = frozenDispute($this);
    Disputes::actAs($finance);
    Disputes::resolve($this, $dispute, compensationBody('buyer', '1'))->assertStatus(403)
        ->assertJsonPath('code', 'compensation_cap_exceeded')->assertJsonPath('details.left_today', '0.0000');

    // Another Finance member has their own day.
    Orders::staff($this, SeedRole::FINANCE);
    Disputes::resolve($this, $dispute, compensationBody('buyer', '1'))->assertOk();

    // The next Cairo day resets the first one; a raised cap applies at once.
    $this->travelTo(now('Africa/Cairo')->addDay()->startOfDay()->addMinutes(5));
    DatabaseActor::elevate('maintenance', fn () => DB::table('setting')->where('setting_key', 'compensation.cap_per_payment_egp')->update(['value_numeric' => 3000]));
    [, $dispute] = frozenDispute($this);
    Disputes::actAs($finance);
    Disputes::resolve($this, $dispute, compensationBody('buyer', '2500'))->assertOk();
});

it('lets the CEO, or anyone holding compensation.uncapped, pay above the caps', function () {
    [, $dispute] = frozenDispute($this);
    Orders::staff($this, SeedRole::CEO);
    Disputes::resolve($this, $dispute, compensationBody('seller', '10000'))->assertOk();
});

it('refuses compensation without compensation.pay, and bad bodies', function () {
    [$order, $dispute] = frozenDispute($this);
    Orders::staff($this, SeedRole::COO);
    Disputes::resolve($this, $dispute, compensationBody('buyer', '100'))->assertForbidden()->assertJsonPath('code', 'permission_denied');

    Orders::staff($this, SeedRole::FINANCE);
    Disputes::resolve($this, $dispute, compensationBody('buyer', '0'))->assertStatus(422)->assertJsonValidationErrors('compensation.amount');
    Disputes::resolve($this, $dispute, compensationBody('buyer', '-5'))->assertStatus(422);
    Disputes::resolve($this, $dispute, compensationBody('nobody', '5'))->assertStatus(422)->assertJsonValidationErrors('compensation.party');
    Disputes::resolve($this, $dispute, compensationBody('buyer', '5', 'because'))->assertStatus(422)->assertJsonValidationErrors('compensation.reason');
    Disputes::resolve($this, $dispute, ['compensation' => ['party' => 'buyer', 'amount' => '5', 'reason' => 'goodwill', 'note' => 'short']])
        ->assertStatus(422)->assertJsonValidationErrors('compensation.note');

    expect($order->refresh()->state->value)->toBe('disputed');
});
