<?php

use App\Enums\ListingDecision;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Listing;
use App\Notifications\ListingDecisionNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 FR-032 (clarified): the seller is told of every staff decision —
// approved, changes requested, rejected, taken down — by SMS, plus email
// when they have one, in their language, after commit. Nothing for the
// seller's own actions or for a refused decision.

const NOTE_FOR_SELLER = 'The hallmark photo is blurred. Please retake it in daylight.';

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::actAsStaff($this, SeedRole::OPERATIONS);
});

function notifiedListing(?string $email, string $lang = 'en', string $state = 'inReview'): Listing
{
    $seller = Customer::factory()->verified()->create(['email' => $email, 'preferred_lang' => $lang]);

    return Listing::factory()->withPhotos(2)->{$state}()->create(['seller_id' => $seller->customer_id]);
}

function staffDecides($test, Listing $listing, string $action, array $body = [])
{
    return $test->postJson(Listings::STAFF_URL."/{$listing->listing_id}/{$action}", $body, Listings::key());
}

it('tells the seller their piece is live, by SMS and email', function () {
    $listing = notifiedListing('sara@example.test');

    staffDecides($this, $listing, 'approve')->assertOk();

    Notification::assertSentTo($listing->seller, ListingDecisionNotification::class, function (ListingDecisionNotification $n, array $channels) use ($listing) {
        $sms = $n->toSms($listing->seller)->content;
        $mail = $n->toMail($listing->seller);

        return $channels === ['inbox', 'mail', 'sms']
            && $n->decision === ListingDecision::APPROVED
            && $n->message === null
            && str_contains($sms, 'Gold ring, 21K') && str_contains($sms, 'live')
            && $mail->subject === 'Your piece is live'
            && str_contains(implode(' ', $mail->introLines), 'Gold ring, 21K');
    });
    Notification::assertCount(1);
});

it('sends the reviewer\'s message when changes are asked', function () {
    $listing = notifiedListing('sara@example.test');

    staffDecides($this, $listing, 'request-changes', ['message' => NOTE_FOR_SELLER])->assertOk();

    Notification::assertSentTo($listing->seller, ListingDecisionNotification::class, function (ListingDecisionNotification $n) use ($listing) {
        return $n->decision === ListingDecision::CHANGES_REQUESTED
            && str_contains($n->toSms($listing->seller)->content, NOTE_FOR_SELLER)
            && in_array(NOTE_FOR_SELLER, $n->toMail($listing->seller)->introLines, true);
    });
});

it('sends the reason of a rejection', function () {
    $listing = notifiedListing(null);

    staffDecides($this, $listing, 'reject', ['reason' => 'The photos are taken from another website.'])->assertOk();

    Notification::assertSentTo($listing->seller, ListingDecisionNotification::class, function (ListingDecisionNotification $n, array $channels) use ($listing) {
        return $channels === ['inbox', 'sms']
            && $n->decision === ListingDecision::REJECTED
            && str_contains($n->toSms($listing->seller)->content, 'The photos are taken from another website.');
    });
});

it('sends the reason of a take-down', function () {
    $listing = notifiedListing('sara@example.test', state: 'live');

    staffDecides($this, $listing, 'takedown', ['reason' => 'A buyer reported the description as wrong.'])->assertOk();

    Notification::assertSentTo($listing->seller, ListingDecisionNotification::class, fn (ListingDecisionNotification $n) => $n->decision === ListingDecision::TAKEN_DOWN
        && str_contains($n->toSms($listing->seller)->content, 'A buyer reported the description as wrong.')
        && $n->toMail($listing->seller)->subject === 'Your piece was taken off the market');
});

it('texts only when the seller has no email, in their language', function () {
    $listing = notifiedListing(null, 'ar');

    staffDecides($this, $listing, 'approve')->assertOk();

    Notification::assertSentTo($listing->seller, ListingDecisionNotification::class, function (ListingDecisionNotification $n, array $channels) use ($listing) {
        $sms = $n->toSms($listing->seller)->content;

        return $channels === ['inbox', 'sms'] && str_contains($sms, 'اتنشرت') && str_contains($sms, '21K') && ! str_contains($sms, 'Gold ring');
    });
});

it('sends nothing when the decision is refused', function () {
    $live = notifiedListing('sara@example.test', state: 'live');
    $waiting = notifiedListing('sara2@example.test');

    staffDecides($this, $live, 'approve')->assertStatus(409);
    staffDecides($this, $waiting, 'request-changes', ['message' => 'short'])->assertStatus(422);
    staffDecides($this, $waiting, 'takedown', ['reason' => 'A reason that is long enough.'])->assertStatus(409);

    Notification::assertNothingSent();
});

it('sends nothing for the seller\'s own actions', function () {
    $seller = Customer::factory()->verified()->create(['email' => 'sara@example.test']);
    $draft = Listing::factory()->withPhotos(2)->create(['seller_id' => $seller->customer_id]);
    $live = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $seller->customer_id]);

    Listings::as($this, $seller)->postJson(Listings::SELLER_URL."/{$draft->listing_id}/submit", [], Listings::key())->assertOk();
    Listings::as($this, $seller)->postJson(Listings::SELLER_URL."/{$live->listing_id}/withdraw", [], Listings::key())->assertOk();

    Notification::assertNothingSent();
});

it('is queued and retried', function () {
    $n = new ListingDecisionNotification(ListingDecision::APPROVED, 'Gold ring, 21K', 'خاتم دهب, 21K');

    expect($n)->toBeInstanceOf(ShouldQueue::class)->and($n->tries)->toBe(3);
});
