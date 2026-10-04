<?php

use App\Enums\OrderEvent;
use App\Enums\SeedRole;
use App\Jobs\NotifyCustomerJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 research R11: IGI grades a stone, staff price the regrade; the
// buyer is asked only then, with the decision clock starting now.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $seller = Customer::factory()->verified()->create();
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create(['seller_id' => $seller->customer_id]);
    $this->order = Orders::accepted($this, $diamond, '200000');
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $this->order)->assertOk();
    Orders::staff($this, SeedRole::IGI_BRANCH);
    Orders::result($this, $this->order, ['measured_stone_grade' => 'VS2 G', 'stone_below_claim' => true])->assertCreated();
});

function propose($test, $order, array $body)
{
    return $test->postJson(Orders::STAFF_URL."/{$order->order_id}/propose-price", $body, Listings::key());
}

it('sets the price, starts the buyer\'s clock and tells both', function () {
    Bus::fake([NotifyCustomerJob::class]);
    $staff = Orders::staff($this, SeedRole::OPERATIONS);

    propose($this, $this->order, ['price' => '108000', 'reason' => 'Grade VS2 instead of VS1 per IGI.'])->assertOk()
        ->assertJsonPath('data.proposed_price', '108000.0000');

    $order = $this->order->refresh();
    expect($order->decision_due_deadline)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'order.price_proposed')->sole()->actor_staff_id)->toBe($staff->staff_id);

    Orders::show($this, Orders::buyer($order), $order)->assertOk()
        ->assertJsonPath('data.inspection.new_price', '108000.0000')
        ->assertJsonPath('data.actions', ['decide', 'report_problem']);

    Bus::assertDispatched(NotifyCustomerJob::class, fn ($job) => $job->customerId === $order->buyer_id && $job->notification->event === OrderEvent::PRICE_PROPOSED);
});

it('refuses a second price, a bad price or reason, and staff without the permission', function () {
    Orders::staff($this, SeedRole::FINANCE);
    propose($this, $this->order, ['price' => '108000', 'reason' => 'Grade VS2 instead of VS1.'])->assertForbidden();

    Orders::staff($this, SeedRole::OPERATIONS);
    propose($this, $this->order, ['price' => '0', 'reason' => 'Grade VS2 instead of VS1.'])->assertStatus(422);
    propose($this, $this->order, ['price' => '108000', 'reason' => 'short'])->assertStatus(422);
    propose($this, $this->order, ['price' => '108000', 'reason' => 'Grade VS2 instead of VS1.'])->assertOk();
    propose($this, $this->order, ['price' => '100000', 'reason' => 'Changed my mind on it.'])->assertStatus(409);
});
