<?php

use App\Actions\Customers\SuspendCustomerAction;
use App\Enums\ListingState;
use App\Enums\SeedRole;
use App\Enums\SuspendedReason;
use App\Exceptions\DomainApiException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Listing;
use App\Support\RequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 FR-037 (Clarification): suspending a customer takes their live
// listings off the market in the same transaction; reinstating puts them
// back. POST /dashboard/customers/{id}/suspend | reinstate (spec 007).

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->staff = Listings::actAsStaff($this, SeedRole::COO);
    $this->seller = Customer::factory()->verified()->create();
    $make = fn (string $state) => Listing::factory()->withPhotos(2)->{$state}()->create(['seller_id' => $this->seller->customer_id]);
    $this->liveA = $make('live');
    $this->liveB = $make('live');
    $this->draft = $make('draft');
    $this->waiting = $make('inReview');
    $this->withdrawn = $make('withdrawn');
    $this->othersLive = Listing::factory()->withPhotos(2)->live()->create();
});

function suspendSeller($test, Customer $customer)
{
    return $test->postJson("/api/v1/dashboard/customers/{$customer->customer_id}/suspend",
        ['reason' => 'off_platform_dealing', 'note' => 'Asked a buyer to pay outside Dahab.'], Listings::key());
}

function reinstateSeller($test, Customer $customer)
{
    return $test->postJson("/api/v1/dashboard/customers/{$customer->customer_id}/reinstate",
        ['note' => 'Reviewed with the customer.'], Listings::key());
}

function marketIds($test): array
{
    return collect(Listings::anonymous($test)->getJson(Listings::MARKET_URL)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
}

it('takes a suspended seller\'s live listings off the market', function () {
    $listedAt = $this->liveA->listed_at;
    expect(marketIds($this))->toHaveCount(3);

    Listings::actAsStaff($this, SeedRole::COO);
    suspendSeller($this, $this->seller)->assertOk();

    expect($this->liveA->fresh()->state)->toBe(ListingState::SUSPENDED_HOLD)
        ->and($this->liveB->fresh()->state)->toBe(ListingState::SUSPENDED_HOLD)
        ->and($this->liveA->fresh()->listed_at->equalTo($listedAt))->toBeTrue()
        ->and($this->draft->fresh()->state)->toBe(ListingState::DRAFT)
        ->and($this->waiting->fresh()->state)->toBe(ListingState::IN_REVIEW)
        ->and($this->withdrawn->fresh()->state)->toBe(ListingState::WITHDRAWN)
        ->and($this->othersLive->fresh()->state)->toBe(ListingState::LIVE)
        ->and(marketIds($this))->toBe([$this->othersLive->listing_id]);

    $change = DB::table('listing_state_change')->where('listing_id', $this->liveA->listing_id)->orderByDesc('change_id')->first();
    $staff = AuditLog::query()->where('action', 'auth.customer.suspended')->where('entity_id', $this->seller->customer_id)->sole();

    expect($change->from_state)->toBe('live')
        ->and($change->to_state)->toBe('suspended_hold')
        ->and($change->note)->toBe('account_suspended')
        ->and($change->actor_staff_id)->toBe($staff->actor_staff_id)
        ->and($staff->after_json['listings_held'])->toBe(2)
        ->and(DB::statement('SET CONSTRAINTS ALL IMMEDIATE'))->toBeTrue();

    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$this->liveA->listing_id}")->assertNotFound();
    Notification::assertNothingSentTo($this->seller);
});

it('shows the held listing to its seller as on hold, with no staff message', function () {
    suspendSeller($this, $this->seller)->assertOk();

    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$this->liveA->listing_id}")->assertOk()
        ->assertJsonPath('data.state', 'suspended_hold')
        ->assertJsonPath('data.staff_message', null)
        ->assertJsonPath('data.can_withdraw', false)
        ->assertJsonPath('data.can_edit', false);
});

it('puts the held listings back when the seller is reinstated', function () {
    $listedAt = $this->liveA->listed_at;
    suspendSeller($this, $this->seller)->assertOk();
    reinstateSeller($this, $this->seller)->assertOk();

    expect($this->liveA->fresh()->state)->toBe(ListingState::LIVE)
        ->and($this->liveB->fresh()->state)->toBe(ListingState::LIVE)
        ->and($this->liveA->fresh()->listed_at->equalTo($listedAt))->toBeTrue()
        ->and($this->draft->fresh()->state)->toBe(ListingState::DRAFT)
        ->and($this->withdrawn->fresh()->state)->toBe(ListingState::WITHDRAWN)
        ->and(marketIds($this))->toHaveCount(3);

    $change = DB::table('listing_state_change')->where('listing_id', $this->liveA->listing_id)->orderByDesc('change_id')->first();
    $audit = AuditLog::query()->where('action', 'auth.customer.unsuspended')->where('entity_id', $this->seller->customer_id)->sole();

    expect($change->from_state)->toBe('suspended_hold')
        ->and($change->to_state)->toBe('live')
        ->and($change->note)->toBe('account_reinstated')
        ->and($audit->after_json['listings_restored'])->toBe(2);
});

it('cannot approve a suspended seller\'s waiting listing until they are reinstated', function () {
    suspendSeller($this, $this->seller)->assertOk();

    $this->postJson(Listings::STAFF_URL."/{$this->waiting->listing_id}/approve", [], Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'seller_suspended');

    reinstateSeller($this, $this->seller)->assertOk();

    $this->postJson(Listings::STAFF_URL."/{$this->waiting->listing_id}/approve", [], Listings::key())
        ->assertOk()->assertJsonPath('data.state', 'live');
});

it('suspends a customer with no listings as before', function () {
    $plain = Customer::factory()->verified()->create();

    suspendSeller($this, $plain)->assertOk();

    expect(AuditLog::query()->where('action', 'auth.customer.suspended')->where('entity_id', $plain->customer_id)->sole()->after_json['listings_held'])->toBe(0);
});

it('suspends and holds together or not at all', function () {
    // Make the hold fail after the customer row was changed: the move live -> suspended_hold
    // is taken out of the allowed moves, so the whole suspension must roll back.
    DB::table('listing_transition')->where('from_state', 'live')->where('to_state', 'suspended_hold')->delete();

    $ctx = RequestContext::forSystem();

    expect(fn () => app(SuspendCustomerAction::class)->handle($this->staff, $this->seller->customer_id, SuspendedReason::OTHER, 'note', $ctx))
        ->toThrow(DomainApiException::class);

    expect($this->seller->fresh()->status->value)->toBe('active')
        ->and($this->liveA->fresh()->state)->toBe(ListingState::LIVE)
        ->and(AuditLog::query()->where('action', 'auth.customer.suspended')->count())->toBe(0);
});
