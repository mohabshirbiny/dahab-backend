<?php

use App\Enums\SeedRole;
use App\Enums\WalletEvent;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Compensation;
use App\Models\Customer;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Disputes;
use Tests\Support\Finance;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 015 FR-004 (Clarification Q1): compensation paid from the Compensation
// page, outside a dispute — the same permission, caps and per-payer lock as
// spec 014, one balanced `compensation` entry, a row with no dispute.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
});

it('pays a verified customer without a dispute: the entry, the row, the wallet, the audit and the message', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $customer = Finance::customer('100');
    $equity = Finance::equity();
    $finance = Finance::staff($this, SeedRole::FINANCE);

    Finance::pay($this, $customer, '800')->assertCreated()
        ->assertJsonPath('data.amount', '800.0000')->assertJsonPath('data.reason', 'wasted_trip')
        ->assertJsonPath('data.dispute', null)->assertJsonPath('data.order', null)->assertJsonPath('data.party', null)
        ->assertJsonPath('data.customer.display_ref', $customer->display_ref)
        ->assertJsonPath('data.paid_by.id', $finance->staff_id);

    $row = DatabaseActor::elevate('maintenance', fn () => Compensation::query()->sole());
    expect(Finance::available($customer))->toBe('900.0000')
        ->and(Finance::equity())->toBe(bcsub($equity, '800', 4))
        ->and(Finance::globalSum())->toBe('0.0000')
        ->and($row->dispute_id)->toBeNull()->and($row->order_id)->toBeNull()->and($row->paid_by)->toBe($finance->staff_id)
        ->and(DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'compensation.paid')->sole()->actor_staff_id))->toBe($finance->staff_id);
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($j) => $j->customerId === $customer->customer_id && $j->notification->event === WalletEvent::COMPENSATION_PAID);
});

it('names one of the customer\'s orders, the party derived; refuses an order of someone else', function () {
    Orders::workedPrices();
    $order = Orders::accepted($this);
    $buyer = Orders::buyer($order);
    $stranger = Finance::customer('0');

    Finance::staff($this, SeedRole::FINANCE);
    Finance::pay($this, $buyer, '100', ['order_id' => $order->order_id])->assertCreated()
        ->assertJsonPath('data.party', 'buyer')->assertJsonPath('data.order.ref', $order->order_ref);
    Finance::pay($this, $stranger, '100', ['order_id' => $order->order_id])->assertStatus(422)->assertJsonValidationErrors('order_id');
});

it('shares the caps with the dispute payments: per payment, per day, and uncapped', function () {
    Orders::workedPrices();
    $customer = Finance::customer('0');
    $finance = Finance::staff($this, SeedRole::FINANCE);

    Finance::pay($this, $customer, '2000.0001')->assertStatus(403)->assertJsonPath('code', 'compensation_cap_exceeded')
        ->assertJsonPath('details.per_payment', '2000.0000')->assertJsonPath('details.left_today', '5000.0000');

    // A dispute payment of 2,000 by the same person counts against today.
    $order = Disputes::awaitingBalance($this);
    $dispute = Disputes::opened($this, $order);
    Finance::actAs($finance);
    Disputes::resolve($this, $dispute, ['compensation' => ['party' => 'buyer', 'amount' => '2000', 'reason' => 'goodwill', 'note' => 'For the delay on our side.']])->assertOk();

    Finance::pay($this, $customer, '2000')->assertCreated();
    Finance::pay($this, $customer, '1000.0001')->assertStatus(403)->assertJsonPath('details.left_today', '1000.0000');
    Finance::pay($this, $customer, '1000')->assertCreated();

    Finance::staff($this, SeedRole::CEO);
    Finance::pay($this, $customer, '25000')->assertCreated();
});

it('pays only a verified customer, suspended or not, recording the status', function () {
    $finance = Finance::staff($this, SeedRole::FINANCE);
    Finance::pay($this, Customer::factory()->pendingVerification()->create(), '10')->assertForbidden()->assertJsonPath('code', 'verification_required');

    $suspended = Customer::factory()->suspended(byStaffId: $finance->staff_id)->create();
    Finance::pay($this, $suspended, '10')->assertCreated();
    $audit = DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'compensation.paid')->sole());
    expect($audit->after_json['customer_status'] ?? null)->toBe('suspended');
});

it('validates the body and replays the same key once', function () {
    $customer = Finance::customer('0');
    Finance::staff($this, SeedRole::FINANCE);

    Finance::pay($this, $customer, '0')->assertStatus(422)->assertJsonValidationErrors('amount');
    Finance::pay($this, $customer, '1.00001')->assertStatus(422)->assertJsonValidationErrors('amount');
    Finance::pay($this, $customer, '5', ['reason' => 'because'])->assertStatus(422)->assertJsonValidationErrors('reason');
    Finance::pay($this, $customer, '5', ['note' => 'short'])->assertStatus(422)->assertJsonValidationErrors('note');
    Finance::pay($this, $customer, '5', ['customer_id' => '00000000-0000-0000-0000-000000000000'])->assertStatus(422)->assertJsonValidationErrors('customer_id');

    $key = (string) Str::uuid();
    Finance::pay($this, $customer, '50', key: $key)->assertCreated();
    Finance::pay($this, $customer, '50', key: $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect(Finance::available($customer))->toBe('50.0000');
});

it('refuses without compensation.pay, and without an Idempotency-Key', function () {
    $customer = Finance::customer('0');
    foreach ([SeedRole::COO, SeedRole::OPERATIONS, SeedRole::VERIFICATION] as $role) {
        Finance::staff($this, $role);
        Finance::pay($this, $customer, '5')->assertForbidden()->assertJsonPath('code', 'permission_denied');
    }
    Finance::staff($this, SeedRole::FINANCE);
    $this->postJson('/api/v1/dashboard/compensation', ['customer_id' => $customer->customer_id, 'amount' => '5', 'reason' => 'goodwill',
        'note' => 'A small goodwill gesture.'])->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
});
