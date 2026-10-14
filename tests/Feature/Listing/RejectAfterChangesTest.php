<?php

use App\Enums\ListingState;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\CommissionCredit;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Listing;
use App\Notifications\ListingDecisionNotification;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;
use Tests\Support\Promo;

uses(RefreshDatabase::class);

// Testing fixes item 8: staff may reject, for good, a listing waiting for the seller's changes
// (manual only, no expiry). POST /dashboard/listings/{listing}/reject from changes_requested.

const AFTER_CHANGES_REASON = 'The seller did not send the clearer hallmark photo.';

beforeEach(function () {
    Storage::fake('identity_private');
    Listings::goldPrice();
    $this->staff = Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->changesRequested()->create(['seller_id' => $this->seller->customer_id]);
});

function rejectAfterChanges($test, Listing $listing)
{
    return $test->postJson(Listings::STAFF_URL."/{$listing->listing_id}/reject", ['reason' => AFTER_CHANGES_REASON], Listings::key());
}

it('offers Reject on a listing waiting for changes and rejects it for good', function () {
    Notification::fake();
    $this->getJson(Listings::STAFF_URL."/{$this->listing->listing_id}")->assertOk()->assertJsonPath('data.can_reject', true);

    rejectAfterChanges($this, $this->listing)->assertOk()
        ->assertJsonPath('data.state', 'rejected')
        ->assertJsonPath('data.can_reject', false);

    $change = DB::table('listing_state_change')->where('listing_id', $this->listing->listing_id)->orderByDesc('change_id')->first();
    expect($this->listing->fresh()->state)->toBe(ListingState::REJECTED)
        ->and($change->from_state)->toBe('changes_requested')
        ->and($change->to_state)->toBe('rejected')
        ->and($change->actor_staff_id)->toBe($this->staff->staff_id)
        ->and($change->note)->toBe(AFTER_CHANGES_REASON);

    $audit = DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->where('action', 'listing.rejected')->sole());
    expect($audit->before_json['state'])->toBe('changes_requested')->and($audit->reason)->toBe(AFTER_CHANGES_REASON);
    Notification::assertSentTo($this->seller, ListingDecisionNotification::class);

    // Final: the seller can no longer resubmit, staff cannot approve.
    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL."/{$this->listing->listing_id}/submit", [], Listings::key())
        ->assertStatus(409)->assertJsonPath('code', 'illegal_listing_transition');
    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $this->postJson(Listings::STAFF_URL."/{$this->listing->listing_id}/approve", [], Listings::key())->assertStatus(409);
});

it('tells the seller in the inbox, linked to their listing', function () {
    rejectAfterChanges($this, $this->listing)->assertOk();

    $row = DatabaseActor::elevate('maintenance', fn () => CustomerNotification::query()->where('customer_id', $this->seller->customer_id)->sole());
    expect($row->type)->toBe('listing.rejected')
        ->and($row->link_kind->value)->toBe('listing')
        ->and($row->link_id)->toBe($this->listing->listing_id);
});

it('voids the commission credits bound to the listing', function () {
    Promo::open();
    $credit = Promo::credit($this->seller, $this->listing);

    rejectAfterChanges($this, $this->listing)->assertOk();

    expect(CommissionCredit::query()->whereKey($credit->credit_id)->sole()->status->value)->toBe('voided')
        ->and(DB::table('commission_credit_event')->where('kind', 'voided')->value('reason'))->toBe('listing_rejected');
});

it('is still refused from draft and live, and the database refuses other moves into rejected', function (string $state) {
    $listing = Listing::factory()->withPhotos(2)->{$state}()->create(['seller_id' => $this->seller->customer_id]);

    rejectAfterChanges($this, $listing)->assertStatus(409)->assertJsonPath('code', 'illegal_listing_transition');
    expect($listing->fresh()->state->value)->toBe($state);
})->with(['draft', 'live']);

it('lists the new move in listing_transition and nothing leaves rejected', function () {
    expect(DB::table('listing_transition')->where('from_state', 'changes_requested')->where('to_state', 'rejected')->exists())->toBeTrue()
        ->and(DB::table('listing_transition')->where('from_state', 'rejected')->exists())->toBeFalse();
});
