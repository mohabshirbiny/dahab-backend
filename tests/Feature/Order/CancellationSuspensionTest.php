<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Listing;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 FR-007, research R8, R14 pass 0, analysis C2: cancellations since
// the last reinstatement reaching suspension.cancellations_threshold (read
// live) suspend the seller — by the sweep, as the system actor, in its own
// operation — with the spec 007/010/011 effects.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->seller = Customer::factory()->verified()->create();
    $this->first = Orders::accepted($this, Orders::ring($this->seller));
    $this->second = Orders::accepted($this, Orders::ring($this->seller));
    $this->onSale = Orders::ring($this->seller);
});

function sellerStatus(Customer $seller): string
{
    return Customer::query()->find($seller->customer_id)->status->value;
}

it('suspends at two cancellations, by the system actor, holding the live listings', function () {
    Orders::cancel($this, $this->seller, $this->first)->assertOk();
    Orders::cancel($this, $this->seller, $this->second)->assertOk();
    expect(sellerStatus($this->seller))->toBe('active');

    Orders::sweep();

    $seller = Customer::query()->find($this->seller->customer_id);
    expect($seller->status->value)->toBe('suspended')
        ->and($seller->suspended_reason->value)->toBe('repeated_cancellations')
        ->and($seller->suspended_by)->toBe(SystemActor::id())
        ->and(Listing::query()->find($this->onSale->listing_id)->state->value)->toBe('suspended_hold')
        ->and(AuditLog::query()->where('action', 'auth.customer.suspended')->sole()->actor_staff_id)->toBe(SystemActor::id());

    Orders::sweep();
    expect(AuditLog::query()->where('action', 'auth.customer.suspended')->count())->toBe(1);
});

it('counts a missed deadline and suspends in the same run', function () {
    Orders::cancel($this, $this->seller, $this->first)->assertOk();
    $this->travelTo($this->second->reach_branch_deadline->addMinute());

    Orders::sweep();

    expect($this->second->refresh()->state->value)->toBe('cancelled_seller')
        ->and(sellerStatus($this->seller))->toBe('suspended');
});

it('restarts the count at reinstatement', function () {
    Orders::cancel($this, $this->seller, $this->first)->assertOk();
    Orders::cancel($this, $this->seller, $this->second)->assertOk();
    Orders::sweep();

    Orders::staff($this, SeedRole::COO);
    $this->postJson("/api/v1/dashboard/customers/{$this->seller->customer_id}/reinstate",
        ['note' => 'Reinstated after a call with the seller.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    $third = Orders::accepted($this, Orders::ring($this->seller));
    Orders::cancel($this, $this->seller, $third)->assertOk();
    Orders::sweep();

    expect(sellerStatus($this->seller))->toBe('active');
});

it('keeps repeated_cancellations for the system: staff cannot choose it', function () {
    Orders::staff($this, SeedRole::COO);

    $this->postJson("/api/v1/dashboard/customers/{$this->seller->customer_id}/suspend",
        ['reason' => 'repeated_cancellations', 'note' => 'Trying the system reason by hand.'], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422);
});

it('reads the threshold live', function () {
    DatabaseActor::elevate('maintenance', fn () => DB::table('setting')->where('setting_key', 'suspension.cancellations_threshold')->update(['value_numeric' => 3]));

    Orders::cancel($this, $this->seller, $this->first)->assertOk();
    Orders::cancel($this, $this->seller, $this->second)->assertOk();
    Orders::sweep();

    expect(sellerStatus($this->seller))->toBe('active');
});
