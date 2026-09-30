<?php

use App\Enums\ListingState;
use App\Enums\PieceCategory;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 US4 scenarios 2–3; FR-006; contract PATCH /customer/me/listings/{listing}.

beforeEach(function () {
    Storage::fake('identity_private');
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->create(['seller_id' => $this->seller->customer_id]);
});

function editListing($test, Customer $customer, Listing|string $listing, array $body, ?string $key = null)
{
    $id = $listing instanceof Listing ? $listing->listing_id : $listing;

    return Listings::as($test, $customer)->patchJson(Listings::SELLER_URL."/{$id}", $body, Listings::key($key));
}

function photoIds(Listing $listing): array
{
    return ListingMedia::query()->where('listing_id', $listing->listing_id)->where('kind', 'photo')
        ->orderBy('position')->pluck('media_id')->all();
}

it('edits the fields of a draft', function () {
    editListing($this, $this->seller, $this->listing, [
        'stated_weight_g' => '8.125',
        'making_charge_per_g' => '240',
        'karat_code' => 18,
        'piece_type_id' => Listings::pieceTypeId(PieceCategory::GOLD, 'Chain'),
        'description' => 'A corrected description that says plainly what the condition of the piece is.',
    ])->assertOk()
        ->assertJsonPath('data.state', 'draft')
        ->assertJsonPath('data.stated_weight_g', '8.125')
        ->assertJsonPath('data.making_charge_per_g', '240.0000')
        ->assertJsonPath('data.karat', 18)
        ->assertJsonPath('data.piece_type.name_en', 'Chain');

    expect((string) $this->listing->fresh()->stated_weight_g)->toBe('8.125')
        ->and(DB::table('listing_state_change')->where('listing_id', $this->listing->listing_id)->count())->toBe(1);
});

it('changes only what is sent', function () {
    editListing($this, $this->seller, $this->listing, ['description' => str_repeat('b', 50)])->assertOk()
        ->assertJsonPath('data.stated_weight_g', '8.000')
        ->assertJsonPath('data.karat', 21)
        ->assertJsonCount(2, 'data.media')
        ->assertJsonCount(1, 'data.branch_options');
});

it('replaces the branch set', function () {
    $new = Branch::factory()->count(2)->create()->pluck('branch_id')->all();

    editListing($this, $this->seller, $this->listing, ['branch_option_ids' => $new])->assertOk()->assertJsonCount(2, 'data.branch_options');

    expect(DB::table('listing_branch_option')->where('listing_id', $this->listing->listing_id)->pluck('branch_id')->sort()->values()->all())->toBe($new);

    editListing($this, $this->seller, $this->listing, ['branch_option_ids' => []])->assertStatus(422)->assertJsonPath('code', 'branch_options_required');
    editListing($this, $this->seller, $this->listing, ['branch_option_ids' => [Branch::factory()->disabled()->create()->branch_id]])
        ->assertStatus(422)->assertJsonPath('code', 'branch_options_required');

    expect(DB::table('listing_branch_option')->where('listing_id', $this->listing->listing_id)->count())->toBe(2);
});

it('adds and removes photos and deletes what was removed', function () {
    [$first, $second] = photoIds($this->listing);
    $removedRef = ListingMedia::query()->find($first)->storage_ref;
    $token = Listings::uploadToken($this, $this->seller);

    editListing($this, $this->seller, $this->listing, ['remove_media_ids' => [$first], 'add_photo_tokens' => [$token]])
        ->assertOk()->assertJsonCount(2, 'data.media');

    $ids = photoIds($this->listing->fresh());

    expect($ids)->toHaveCount(2)->toContain($second)->not->toContain($first)
        ->and(Storage::disk('identity_private')->exists($removedRef))->toBeFalse()
        ->and(Storage::disk('identity_private')->exists(ListingMedia::query()->find($second)->storage_ref))->toBeTrue();
});

it('refuses to remove a file of another listing', function () {
    $other = Listing::factory()->withPhotos(1)->create(['seller_id' => $this->seller->customer_id]);

    editListing($this, $this->seller, $this->listing, ['remove_media_ids' => photoIds($other)])
        ->assertStatus(422)->assertJsonValidationErrors('remove_media_ids');

    expect(photoIds($other))->toHaveCount(1);
});

it('reorders the photos', function () {
    [$first, $second] = photoIds($this->listing);

    editListing($this, $this->seller, $this->listing, ['photo_order' => [$second, $first]])->assertOk();
    expect(photoIds($this->listing))->toBe([$second, $first]);

    editListing($this, $this->seller, $this->listing, ['photo_order' => [$second]])->assertStatus(422)->assertJsonValidationErrors('photo_order');
});

it('keeps a listing to six photos', function () {
    $tokens = Listings::photoTokens($this, $this->seller, 5);

    editListing($this, $this->seller, $this->listing, ['add_photo_tokens' => $tokens])->assertStatus(422)->assertJsonValidationErrors('photo_tokens');
    expect(photoIds($this->listing))->toHaveCount(2);

    editListing($this, $this->seller, $this->listing, ['add_photo_tokens' => array_slice($tokens, 0, 4)])->assertOk()->assertJsonCount(6, 'data.media');
});

it('replaces, removes and leaves the single files', function () {
    $video = Listings::uploadToken($this, $this->seller, 'listing_video', Listings::mp4());
    $invoice = Listings::uploadToken($this, $this->seller, 'listing_invoice', Listings::pdf());

    editListing($this, $this->seller, $this->listing, ['video_token' => $video, 'invoice_token' => $invoice])->assertOk()->assertJsonCount(4, 'data.media');
    $firstVideo = ListingMedia::query()->where('listing_id', $this->listing->listing_id)->where('kind', 'video')->sole();

    // A new token replaces the video; the invoice is not mentioned, so it stays.
    $again = Listings::uploadToken($this, $this->seller, 'listing_video', Listings::mp4('second.mp4'));
    editListing($this, $this->seller, $this->listing, ['video_token' => $again])->assertOk()->assertJsonCount(4, 'data.media');

    $kinds = fn () => ListingMedia::query()->where('listing_id', $this->listing->listing_id)->pluck('kind')->map->value->sort()->values()->all();
    expect($kinds())->toBe(['invoice', 'photo', 'photo', 'video'])
        ->and(ListingMedia::query()->find($firstVideo->media_id))->toBeNull()
        ->and(Storage::disk('identity_private')->exists($firstVideo->storage_ref))->toBeFalse();

    // null removes.
    editListing($this, $this->seller, $this->listing, ['video_token' => null])->assertOk()->assertJsonCount(3, 'data.media');
    expect($kinds())->toBe(['invoice', 'photo', 'photo']);
});

it('refuses a bad upload token and changes nothing', function () {
    editListing($this, $this->seller, $this->listing, ['add_photo_tokens' => [Str::random(48)], 'description' => str_repeat('c', 50)])
        ->assertStatus(422)->assertJsonPath('code', 'upload_token_invalid');

    expect($this->listing->fresh()->description)->not->toBe(str_repeat('c', 50));
});

it('keeps the rules of the category', function (array $body, string $field) {
    editListing($this, $this->seller, $this->listing, $body)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'the category itself' => [['category' => 'diamond'], 'category'],
    'an asking price on gold' => [['asking_price' => '1000'], 'asking_price'],
    'a disabled karat' => [['karat_code' => 22], 'karat_code'],
    'a piece type of another category' => [['piece_type_id' => 9999], 'piece_type_id'],
    'a weight of zero' => [['stated_weight_g' => '0'], 'stated_weight_g'],
    'a description of 2001 characters' => [['description' => str_repeat('x', 2001)], 'description'],
]);

it('refuses to empty the karat or the weight of a gold piece', function (string $field) {
    editListing($this, $this->seller, $this->listing, [$field => null])->assertStatus(422)->assertJsonPath('code', 'gold_needs_karat_weight');
})->with(['karat_code', 'stated_weight_g']);

it('edits a listing sent back for changes, then resubmits it', function () {
    $listing = Listing::factory()->withPhotos(2)->changesRequested('Please say whether the chain is included.')
        ->create(['seller_id' => $this->seller->customer_id]);

    editListing($this, $this->seller, $listing, ['description' => 'The chain is included. Worn a few times and kept in its box since then.'])
        ->assertOk()->assertJsonPath('data.state', 'changes_requested')
        ->assertJsonPath('data.staff_message', 'Please say whether the chain is included.');

    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL."/{$listing->listing_id}/submit", [], Listings::key())
        ->assertOk()->assertJsonPath('data.state', 'in_review');
});

it('refuses to edit a listing in any other state', function (string $state) {
    $listing = Listing::factory()->withPhotos(2)->{$state}()->create(['seller_id' => $this->seller->customer_id]);

    editListing($this, $this->seller, $listing, ['description' => str_repeat('d', 50)])
        ->assertStatus(409)->assertJsonPath('code', 'listing_not_editable');

    expect($listing->fresh()->description)->not->toBe(str_repeat('d', 50));
})->with(['inReview', 'live', 'withdrawn', 'rejected', 'suspendedHold']);

it('hides another seller\'s listing and refuses customers who may not trade', function () {
    $other = Customer::factory()->verified()->create();
    $suspended = Customer::factory()->suspended(byStaffId: SystemActor::id())->create();
    $theirs = Listing::factory()->create(['seller_id' => $suspended->customer_id]);

    editListing($this, $other, $this->listing, ['description' => str_repeat('e', 50)])->assertNotFound();
    editListing($this, $suspended, $theirs, ['description' => str_repeat('e', 50)])->assertForbidden()->assertJsonPath('code', 'account_suspended');

    expect($this->listing->fresh()->state)->toBe(ListingState::DRAFT);
});

it('needs an idempotency key and replays the same result', function () {
    Listings::as($this, $this->seller)->patchJson(Listings::SELLER_URL."/{$this->listing->listing_id}", ['description' => str_repeat('f', 50)])
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

    $key = (string) Str::uuid();
    $token = Listings::uploadToken($this, $this->seller);

    editListing($this, $this->seller, $this->listing, ['add_photo_tokens' => [$token]], $key)->assertOk()->assertJsonCount(3, 'data.media');
    editListing($this, $this->seller, $this->listing, ['add_photo_tokens' => [$token]], $key)->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')->assertJsonCount(3, 'data.media');

    expect(photoIds($this->listing))->toHaveCount(3);
});
