<?php

use App\Actions\Listings\ChangeListingPriceAction;
use App\Actions\Listings\SubmitListingAction;
use App\Actions\Listings\UpdateListingAction;
use App\Enums\ListingState;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingPriceChange;
use App\Support\DatabaseActor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// Spec 003 FR-002 defence in depth (review B3): the seller's listing moves lock the row by id,
// so with row security bypassed a stranger could edit, reprice or submit someone else's piece.
// Each Action now checks the seller first. The elevated scope sees every row, as a BYPASSRLS
// role would; the listing must come out of a refused call exactly as it went in.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    $this->stranger = Customer::factory()->verified()->create();
});

/** The columns a refused call must leave alone. */
function listingSnapshot(Listing $listing): array
{
    return $listing->fresh()->only(['seller_id', 'state', 'making_charge_per_g', 'asking_price', 'description', 'stated_weight_g', 'updated_at']);
}

it('refuses a stranger editing another seller\'s draft', function () {
    $listing = Listing::factory()->withPhotos(2)->draft()->create();
    $before = listingSnapshot($listing);

    DatabaseActor::elevate('maintenance', function () use ($listing) {
        expect(Listing::query()->whereKey($listing->listing_id)->exists())->toBeTrue() // the scope sees it
            ->and(fn () => app(UpdateListingAction::class)->handle($this->stranger, $listing->listing_id, [
                'description' => 'Changed by someone who does not own this piece.', 'making_charge_per_g' => '1.00',
            ]))->toThrow(ModelNotFoundException::class);
    });

    expect(listingSnapshot($listing))->toBe($before);
});

it('refuses a stranger repricing another seller\'s live piece', function () {
    $listing = Listing::factory()->withPhotos(2)->live()->create();
    $before = listingSnapshot($listing);

    DatabaseActor::elevate('maintenance', fn () => expect(fn () => app(ChangeListingPriceAction::class)
        ->handle($this->stranger, $listing->listing_id, '1.00'))->toThrow(ModelNotFoundException::class));

    expect(listingSnapshot($listing))->toBe($before)
        ->and(DatabaseActor::elevate('maintenance', fn () => ListingPriceChange::query()->where('listing_id', $listing->listing_id)->count()))->toBe(0);
});

it('refuses a stranger submitting another seller\'s draft for review', function () {
    $listing = Listing::factory()->withPhotos(2)->draft()->create();
    $before = listingSnapshot($listing);

    DatabaseActor::elevate('maintenance', fn () => expect(fn () => app(SubmitListingAction::class)
        ->handle($this->stranger, $listing->listing_id))->toThrow(ModelNotFoundException::class));

    expect(listingSnapshot($listing))->toBe($before)
        ->and($listing->fresh()->state)->toBe(ListingState::DRAFT);
});
