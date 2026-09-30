<?php

use App\Enums\ListingState;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Karat;
use App\Models\Listing;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 US2 scenarios 2–5, 7, 9, 10; FR-028–FR-031: approve, ask for
// changes and reject — POST /dashboard/listings/{listing}/approve |
// request-changes | reject.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->staff = Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->inReview()->create(['seller_id' => $this->seller->customer_id]);
});

function decide($test, Listing $listing, string $action, array $body = [], ?string $key = null)
{
    return $test->postJson(Listings::STAFF_URL."/{$listing->listing_id}/{$action}", $body, Listings::key($key));
}

function listingAudits(Listing $listing, string $action)
{
    return AuditLog::query()->where('action', $action)->where('entity_id', $listing->listing_id)->get();
}

function lastChange(Listing $listing): object
{
    return DB::table('listing_state_change')->where('listing_id', $listing->listing_id)->orderByDesc('change_id')->first();
}

const LONG_NOTE = 'The hallmark photo is blurred and we cannot read the karat.';

it('approves a listing: live at once, with its listing time, audited', function () {
    decide($this, $this->listing, 'approve')->assertOk()
        ->assertJsonPath('data.state', 'live')
        ->assertJsonPath('data.can_approve', false)
        ->assertJsonPath('data.can_take_down', true)
        ->assertJsonPath('data.history.2.to_state', 'live')
        ->assertJsonPath('data.history.2.actor.type', 'staff');

    $fresh = $this->listing->fresh();
    $change = lastChange($this->listing);
    $audit = listingAudits($this->listing, 'listing.approved');

    expect($fresh->state)->toBe(ListingState::LIVE)
        ->and($fresh->listed_at)->not->toBeNull()
        ->and($change->from_state)->toBe('in_review')
        ->and($change->to_state)->toBe('live')
        ->and($change->actor_staff_id)->toBe($this->staff->staff_id)
        ->and($change->actor_customer_id)->toBeNull()
        ->and($audit)->toHaveCount(1)
        ->and($audit[0]->actor_staff_id)->toBe($this->staff->staff_id)
        ->and($audit[0]->entity_type)->toBe('listing')
        ->and($audit[0]->before_json)->toBe(['state' => 'in_review'])
        ->and($audit[0]->after_json['state'])->toBe('live')
        ->and(DB::statement('SET CONSTRAINTS ALL IMMEDIATE'))->toBeTrue();

    // It is on the public market now.
    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$this->listing->listing_id}")->assertOk();
});

it('asks for changes with a message the seller reads', function () {
    decide($this, $this->listing, 'request-changes', ['message' => LONG_NOTE])->assertOk()
        ->assertJsonPath('data.state', 'changes_requested')
        ->assertJsonPath('data.sent_back_count', 1);

    $change = lastChange($this->listing);
    $audit = listingAudits($this->listing, 'listing.changes_requested');

    expect($this->listing->fresh()->state)->toBe(ListingState::CHANGES_REQUESTED)
        ->and($this->listing->fresh()->listed_at)->toBeNull()
        ->and($change->note)->toBe(LONG_NOTE)
        ->and($change->actor_staff_id)->toBe($this->staff->staff_id)
        ->and($audit)->toHaveCount(1)
        ->and($audit[0]->reason)->toBe(LONG_NOTE)
        ->and($audit[0]->after_json['state'])->toBe('changes_requested');

    Listings::as($this, $this->seller)->getJson(Listings::SELLER_URL."/{$this->listing->listing_id}")->assertOk()
        ->assertJsonPath('data.staff_message', LONG_NOTE)->assertJsonPath('data.can_edit', true);
});

it('rejects a listing for good, with a reason the seller reads', function () {
    Listings::actAsStaff($this, SeedRole::COO);
    decide($this, $this->listing, 'reject', ['reason' => 'The photos are taken from another website.'])->assertOk()
        ->assertJsonPath('data.state', 'rejected')
        ->assertJsonPath('data.can_approve', false)->assertJsonPath('data.can_reject', false);

    $audit = listingAudits($this->listing, 'listing.rejected');

    expect($this->listing->fresh()->state)->toBe(ListingState::REJECTED)
        ->and(lastChange($this->listing)->note)->toBe('The photos are taken from another website.')
        ->and($audit)->toHaveCount(1)
        ->and($audit[0]->reason)->toBe('The photos are taken from another website.');

    // Final: not editable, not resubmittable, not approvable, not on the market.
    $seller = fn () => Listings::as($this, $this->seller);
    $id = $this->listing->listing_id;
    $seller()->getJson(Listings::SELLER_URL."/{$id}")->assertOk()->assertJsonPath('data.staff_message', 'The photos are taken from another website.');
    $seller()->patchJson(Listings::SELLER_URL."/{$id}", ['description' => str_repeat('a', 50)], Listings::key())->assertStatus(409)->assertJsonPath('code', 'listing_not_editable');
    $seller()->postJson(Listings::SELLER_URL."/{$id}/submit", [], Listings::key())->assertStatus(409)->assertJsonPath('code', 'illegal_listing_transition');
    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$id}")->assertNotFound();

    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    decide($this, $this->listing, 'approve')->assertStatus(409)->assertJsonPath('code', 'illegal_listing_transition');
    decide($this, $this->listing, 'request-changes', ['message' => LONG_NOTE])->assertStatus(409);

    expect($this->listing->fresh()->state)->toBe(ListingState::REJECTED);
});

it('needs the message or the reason, 10 to 1000 characters', function (string $action, string $field, mixed $value) {
    decide($this, $this->listing, $action, $value === null ? [] : [$field => $value])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors($field);

    expect($this->listing->fresh()->state)->toBe(ListingState::IN_REVIEW)
        ->and(AuditLog::query()->where('entity_type', 'listing')->count())->toBe(0);
})->with([
    ['request-changes', 'message', null],
    ['request-changes', 'message', 'too short'],
    ['request-changes', 'message', str_repeat('a', 1001)],
    ['reject', 'reason', null],
    ['reject', 'reason', 'short'],
    ['reject', 'reason', str_repeat('a', 1001)],
]);

it('does not accept the other field name', function () {
    decide($this, $this->listing, 'request-changes', ['reason' => LONG_NOTE])->assertStatus(422)->assertJsonValidationErrors('message');
    decide($this, $this->listing, 'reject', ['message' => LONG_NOTE])->assertStatus(422)->assertJsonValidationErrors('reason');
});

it('decides only on a listing that is in review, and writes nothing otherwise', function (string $state, string $action) {
    $listing = Listing::factory()->withPhotos(2)->{$state}()->create(['seller_id' => $this->seller->customer_id]);
    $changes = DB::table('listing_state_change')->count();

    decide($this, $listing, $action, ['message' => LONG_NOTE, 'reason' => LONG_NOTE])
        ->assertStatus(409)->assertJsonPath('code', 'illegal_listing_transition');

    expect(DB::table('listing_state_change')->count())->toBe($changes)
        ->and(AuditLog::query()->where('entity_type', 'listing')->count())->toBe(0)
        ->and($listing->fresh()->state->value)->toBe(Str::snake($state));

    Notification::assertNothingSent();
})->with([
    ['draft', 'approve'], ['live', 'approve'], ['changesRequested', 'approve'], ['withdrawn', 'approve'], ['suspendedHold', 'approve'],
    ['draft', 'request-changes'], ['live', 'request-changes'], ['changesRequested', 'request-changes'],
    ['draft', 'reject'], ['live', 'reject'], ['rejected', 'reject'], ['withdrawn', 'reject'],
]);

it('refuses to approve a suspended seller\'s listing, and leaves it in review', function () {
    DB::table('customer')->where('customer_id', $this->seller->customer_id)->update([
        'status' => 'suspended', 'status_before_suspension' => 'active', 'is_suspended' => true,
        'suspended_reason' => 'other', 'suspended_by' => SystemActor::id(), 'suspended_at' => now(),
    ]);

    decide($this, $this->listing, 'approve')->assertStatus(409)->assertJsonPath('code', 'seller_suspended');

    expect($this->listing->fresh()->state)->toBe(ListingState::IN_REVIEW)
        ->and(listingAudits($this->listing, 'listing.approved'))->toHaveCount(0);
    Notification::assertNothingSent();

    // It can still be sent back or rejected.
    decide($this, $this->listing, 'reject', ['reason' => LONG_NOTE])->assertOk()->assertJsonPath('data.state', 'rejected');
});

it('refuses to approve a listing whose karat was turned off, and leaves it in review', function () {
    Karat::query()->whereKey(21)->update(['is_enabled' => false]);
    $changes = DB::table('listing_state_change')->count();

    decide($this, $this->listing, 'approve')->assertStatus(409)->assertJsonPath('code', 'karat_disabled');

    expect($this->listing->fresh()->state)->toBe(ListingState::IN_REVIEW)
        ->and($this->listing->fresh()->listed_at)->toBeNull()
        ->and(DB::table('listing_state_change')->count())->toBe($changes)
        ->and(AuditLog::query()->where('entity_type', 'listing')->count())->toBe(0);
    Notification::assertNothingSent();
    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$this->listing->listing_id}")->assertNotFound();

    // Still in review: it can be sent back, and approved once the karat is on again.
    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    Karat::query()->whereKey(21)->update(['is_enabled' => true]);
    decide($this, $this->listing, 'approve')->assertOk()->assertJsonPath('data.state', 'live');
});

it('approves a diamond, which has no karat', function () {
    Karat::query()->update(['is_enabled' => false]);
    $diamond = Listing::factory()->diamond()->withPhotos(3)->inReview()->create(['seller_id' => $this->seller->customer_id]);

    decide($this, $diamond, 'approve')->assertOk()->assertJsonPath('data.state', 'live');
});

it('needs an idempotency key and acts once', function (string $action, array $body, string $audit) {
    $this->postJson(Listings::STAFF_URL."/{$this->listing->listing_id}/{$action}", $body)
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

    $key = (string) Str::uuid();
    $first = decide($this, $this->listing, $action, $body, $key)->assertOk();
    $replay = decide($this, $this->listing, $action, $body, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($replay->json('data.state'))->toBe($first->json('data.state'))
        ->and(listingAudits($this->listing, $audit))->toHaveCount(1)
        ->and(DB::table('listing_state_change')->where('listing_id', $this->listing->listing_id)->count())->toBe(3);

    Notification::assertCount(1);
})->with([
    ['approve', [], 'listing.approved'],
    ['request-changes', ['message' => LONG_NOTE], 'listing.changes_requested'],
    ['reject', ['reason' => LONG_NOTE], 'listing.rejected'],
]);

it('answers not found for a listing that does not exist', function () {
    $this->postJson(Listings::STAFF_URL.'/'.Str::uuid().'/approve', [], Listings::key())->assertNotFound();
});
