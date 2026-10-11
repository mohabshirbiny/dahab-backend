<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\OrderExtensionRequest;
use App\Models\PayoutAccount;
use App\Support\DatabaseActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuyRequests;
use Tests\Support\Disputes;
use Tests\Support\Listings;
use Tests\Support\Orders;
use Tests\Support\TopUps;
use Tests\Support\Withdrawals;

uses(RefreshDatabase::class);

// Spec 019 US7, D4, D5: what a pause and a stop of everything do, and what they leave alone.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
});

function pauseSet(string $level, ?string $category = null, string $reason = 'x')
{
    return test()->postJson('/api/v1/dashboard/category-controls',
        array_filter(['level' => $level, 'category' => $category, 'reason' => $reason]), ['Idempotency-Key' => (string) Str::uuid()]);
}

function pauseClear(string $level, ?string $category = null)
{
    return test()->postJson('/api/v1/dashboard/category-controls/clear',
        array_filter(['level' => $level, 'category' => $category]), ['Idempotency-Key' => (string) Str::uuid()]);
}

function pauseMarketIds($test): array
{
    return collect(Listings::anonymous($test)->getJson(Listings::MARKET_URL.'?per_page=100')->assertOk()->json('data'))->pluck('id')->all();
}

it('hides live pieces nobody asked for, keeps requested ones, and brings the rest back on reopening', function () {
    $free = Orders::ring();
    $asked = Orders::ring();
    BuyRequests::queued($this, BuyRequests::funded('60000'), $asked);
    expect(pauseMarketIds($this))->toContain($free->listing_id)->toContain($asked->listing_id);

    TopUps::actAsStaff($this, SeedRole::COO);
    pauseSet('pause_category', 'gold')->assertCreated();

    $ids = pauseMarketIds($this);
    expect($ids)->not->toContain($free->listing_id)->toContain($asked->listing_id);
    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$free->listing_id}")->assertNotFound();

    // A buyer cannot request the hidden piece; a seller can add nothing new to the category.
    $buyer = BuyRequests::funded('60000');
    BuyRequests::send($this, $buyer, $free, ['confirm_locked_price' => '1000'])->assertNotFound();

    TopUps::actAsStaff($this, SeedRole::COO);
    pauseClear('pause_category', 'gold')->assertOk();
    expect(pauseMarketIds($this))->toContain($free->listing_id);
});

it('answers a waiting request for more time as refused, in the name of who paused, and refuses new ones', function () {
    $order = Orders::accepted($this);
    Disputes::askMoreTime($this, $order)->assertCreated();
    $deadline = $order->reach_branch_deadline;

    $staff = TopUps::actAsStaff($this, SeedRole::COO);
    pauseSet('pause_category', 'gold')->assertCreated();

    $request = DatabaseActor::elevate('maintenance', fn () => OrderExtensionRequest::query()->where('order_id', $order->order_id)->sole());
    expect($request->state->value)->toBe('refused')
        ->and($request->answered_by)->toBe($staff->staff_id)
        ->and($request->answer_note)->toContain('category paused')
        ->and($order->refresh()->reach_branch_deadline->equalTo($deadline))->toBeTrue();

    // New ones are refused while paused; the order itself carries on.
    Disputes::askMoreTime($this, $order)->assertStatus(409)->assertJsonPath('code', 'category_paused');
});

it('stops everything: new listings and buy requests are refused, and a locked order still goes through inspection', function () {
    $order = Orders::accepted($this);

    TopUps::actAsStaff($this, SeedRole::COO);
    pauseSet('stop_everything', null, 'Bank outage')->assertCreated();

    // Anything with a locked price finishes: the piece is received and inspected as usual.
    Orders::inspected($this, $order);
    expect($order->refresh()->state->value)->not->toBe('cancelled_staff');

    // No new listing and no new buy request.
    $seller = Customer::factory()->verified()->create();
    Listings::as($this, $seller)->postJson(Listings::SELLER_URL, Listings::goldBody($this, $seller), Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'platform_stopped');
    $other = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $seller->customer_id]);
    BuyRequests::send($this, BuyRequests::funded('60000'), $other)->assertStatus(409)->assertJsonPath('code', 'platform_stopped');
});

it('refuses new withdrawals and staff release while everything is stopped, and lets the customer cancel', function () {
    $withdrawal = Withdrawals::requested($this);
    $reviewed = Withdrawals::underReview($this, $withdrawal);

    TopUps::actAsStaff($this, SeedRole::COO);
    pauseSet('stop_everything', null, 'Bank outage')->assertCreated();

    // Staff release is refused; the money stays held.
    Withdrawals::staff($this, SeedRole::FINANCE);
    Withdrawals::release($this, $reviewed)->assertStatus(409)->assertJsonPath('code', 'platform_stopped');

    // The customer cancels: nothing leaves the account.
    $customer = Withdrawals::customer($reviewed);
    Listings::as($this, $customer)->postJson(Withdrawals::CUSTOMER."/withdrawals/{$reviewed->withdrawal_id}/cancel", [], Listings::key())
        ->assertOk()->assertJsonPath('data.state', 'cancelled');

    // A new withdrawal is refused while stopped.
    $account = PayoutAccount::query()->where('customer_id', $customer->customer_id)->where('is_in_use', true)->firstOrFail();
    $confirmation = Withdrawals::confirmedFor($this, $customer, '1000', $account->payout_account_id);
    Withdrawals::submit($this, $customer, $confirmation, '1000', $account->payout_account_id)
        ->assertStatus(409)->assertJsonPath('code', 'platform_stopped');
});
