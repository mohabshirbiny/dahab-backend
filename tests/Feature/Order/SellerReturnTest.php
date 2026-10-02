<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\SellerReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 FR-019, research R10, R13: a returned piece waits at the branch;
// the seller collects it with their code (staff hand it over) or relists it;
// past the window it is unclaimed.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::inspected($this, Orders::accepted($this), '9.950');
    $this->seller = Orders::seller($this->order);
    $this->travelTo($this->order->balance_due_deadline->addMinute());
    Orders::sweep();
    $this->code = Orders::show($this, $this->seller, $this->order->refresh())->json('data.return_code');
});

function returnHandover($test, $order, string $code)
{
    return $test->postJson(Orders::STAFF_URL."/{$order->order_id}/seller-return/handover", ['code' => $code], Listings::key());
}

function relist($test, Customer $seller, $order, ?string $key = null)
{
    return Listings::as($test, $seller)->postJson(Orders::CUSTOMER_URL."/{$order->order_id}/relist", [], Listings::key($key));
}

it('relists with the IGI-measured weight and closes the return, audited', function () {
    relist($this, $this->seller, $this->order)->assertOk()
        ->assertJsonPath('data.seller_return.relisted_at', fn ($v) => $v !== null)
        ->assertJsonPath('data.actions', []);

    $listing = Listing::query()->find($this->order->listing_id);
    expect($listing->state->value)->toBe('live')
        ->and((string) $listing->stated_weight_g)->toBe('9.950')
        ->and($listing->changes()->reorder()->latest('change_id')->first()->note)->toContain('10.000 g -> 21K 9.950 g')
        ->and(AuditLog::query()->where('action', 'order.relisted')->sole()->actor_customer_id)->toBe($this->seller->customer_id);

    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$listing->listing_id}")->assertOk();
    relist($this, $this->seller, $this->order)->assertStatus(409)->assertJsonPath('code', 'illegal_listing_transition');
});

it('refuses a relist while the seller is suspended', function () {
    Orders::staff($this, SeedRole::COO);
    $this->postJson("/api/v1/dashboard/customers/{$this->seller->customer_id}/suspend",
        ['reason' => 'other', 'note' => 'Testing the trade gate on relisting.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    relist($this, $this->seller, $this->order)->assertForbidden()->assertJsonPath('code', 'account_suspended');
});

it('hands the piece back against the seller code, at the branch, once', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $staff = Orders::staff($this, SeedRole::IGI_BRANCH, $this->order->branch_id);

    returnHandover($this, $this->order, $this->code)->assertOk();

    $return = SellerReturn::query()->where('order_id', $this->order->order_id)->sole();
    expect($return->collected_at)->not->toBeNull()
        ->and($return->handover_by)->toBe($staff->staff_id)
        ->and(Listing::query()->find($this->order->listing_id)->state->value)->toBe('withdrawn');
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $this->seller->customer_id && $job->notification->event === OrderEvent::COLLECTED);

    returnHandover($this, $this->order, $this->code)->assertStatus(409);
});

it('counts wrong codes, locks after five for fifteen minutes, and audits each', function () {
    Orders::staff($this, SeedRole::IGI_BRANCH);
    $wrong = $this->code === '000000' ? '111111' : '000000';

    for ($left = 4; $left >= 1; $left--) {
        returnHandover($this, $this->order, $wrong)->assertStatus(422)
            ->assertJsonPath('code', 'invalid_collection_code')->assertJsonPath('details.attempts_left', $left);
    }
    returnHandover($this, $this->order, $wrong)->assertStatus(429)->assertJsonPath('code', 'handover_locked');
    returnHandover($this, $this->order, $this->code)->assertStatus(429);

    expect(AuditLog::query()->where('action', 'order.handover_failed')->count())->toBe(5);

    $this->travel(16)->minutes();
    returnHandover($this, $this->order, $this->code)->assertOk();
});

it('refuses another branch', function () {
    Orders::staff($this, SeedRole::IGI_BRANCH, Branch::factory()->create()->branch_id);

    returnHandover($this, $this->order, $this->code)->assertForbidden()->assertJsonPath('code', 'wrong_branch');
});

it('marks the piece unclaimed when the window passes, and still hands it over later', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $this->travelTo(SellerReturn::query()->where('order_id', $this->order->order_id)->sole()->return_deadline->addMinute());

    Orders::sweep();

    expect(Listing::query()->find($this->order->listing_id)->state->value)->toBe('seller_unclaimed');
    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->notification->event === OrderEvent::RETURN_WINDOW_PASSED);

    Orders::staff($this, SeedRole::IGI_BRANCH);
    returnHandover($this, $this->order, $this->code)->assertOk();
    expect(Listing::query()->find($this->order->listing_id)->state->value)->toBe('withdrawn');
});
