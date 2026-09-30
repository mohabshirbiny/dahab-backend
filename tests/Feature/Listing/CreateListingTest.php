<?php

use App\Enums\ListingState;
use App\Enums\PieceCategory;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Karat;
use App\Models\Listing;
use App\Models\PieceType;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 US1 scenarios 1–4, 6–8; FR-001–FR-005, FR-007–FR-009, FR-039;
// contract POST /customer/me/listings.

beforeEach(function () {
    Storage::fake('identity_private');
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
});

function createListing($test, Customer $customer, array $body, ?string $key = null)
{
    return Listings::as($test, $customer)->postJson(Listings::SELLER_URL, $body, Listings::key($key));
}

it('creates a gold draft with everything that belongs to it', function () {
    $branches = Branch::factory()->count(2)->create();
    $body = Listings::goldBody($this, $this->seller, [
        'branch_option_ids' => $branches->pluck('branch_id')->all(),
        'video_token' => Listings::uploadToken($this, $this->seller, 'listing_video', Listings::mp4()),
        'invoice_token' => Listings::uploadToken($this, $this->seller, 'listing_invoice', Listings::pdf()),
    ]);

    $res = createListing($this, $this->seller, $body)
        ->assertCreated()
        ->assertJsonPath('data.state', 'draft')
        ->assertJsonPath('data.category', 'gold')
        ->assertJsonPath('data.karat', 21)
        ->assertJsonPath('data.stated_weight_g', '8.000')
        ->assertJsonPath('data.making_charge_per_g', '250.0000')
        ->assertJsonPath('data.asking_price', null)
        ->assertJsonPath('data.piece_type.name_en', 'Ring')
        ->assertJsonPath('data.price_available', true)
        ->assertJsonPath('data.price_is_indicative', true)
        ->assertJsonPath('data.staff_message', null)
        ->assertJsonPath('data.listed_at', null)
        ->assertJsonPath('data.can_edit', true)
        ->assertJsonPath('data.can_submit', true)
        ->assertJsonPath('data.can_withdraw', false)
        ->assertJsonCount(4, 'data.media')
        ->assertJsonCount(2, 'data.branch_options');

    $listing = Listing::query()->sole();
    $media = DB::table('listing_media')->where('listing_id', $listing->listing_id)->orderBy('kind')->orderBy('position')->get();

    expect($res->json('data.id'))->toBe($listing->listing_id)
        ->and($listing->seller_id)->toBe($this->seller->customer_id)
        ->and($listing->state)->toBe(ListingState::DRAFT)
        ->and($listing->listed_at)->toBeNull()
        ->and($media->pluck('kind')->all())->toBe(['invoice', 'photo', 'photo', 'video'])
        ->and($media->where('kind', 'invoice')->first()->is_private)->toBeTrue()
        ->and($media->where('kind', '!=', 'invoice')->pluck('is_private')->unique()->all())->toBe([false])
        ->and($media->where('kind', 'photo')->pluck('position')->all())->toBe([0, 1])
        ->and($media->where('kind', 'invoice')->first()->mime)->toBe('application/pdf')
        ->and($media->where('kind', 'video')->first()->mime)->toBe('video/mp4')
        ->and(DB::table('listing_branch_option')->where('listing_id', $listing->listing_id)->pluck('branch_id')->sort()->values()->all())
        ->toBe($branches->pluck('branch_id')->sort()->values()->all())
        ->and(DB::table('listing_queue_seq')->where('listing_id', $listing->listing_id)->value('next_pos'))->toBe(1);

    // The acceptance is evidence: tied to the piece and in the general record.
    $declaration = DB::table('listing_ownership_declaration')->where('listing_id', $listing->listing_id)->first();
    $acceptance = DB::table('agreement_acceptance')->where('customer_id', $this->seller->customer_id)->first();
    expect($declaration->customer_id)->toBe($this->seller->customer_id)
        ->and($declaration->legal_doc_id)->toBe(Listings::declarationId())
        ->and($acceptance->context)->toBe('list_piece')
        ->and($acceptance->legal_doc_id)->toBe(Listings::declarationId())
        ->and($acceptance->ip_address)->not->toBeNull();

    // The creation is the first row of the history, in the seller's name.
    $history = DB::table('listing_state_change')->where('listing_id', $listing->listing_id)->get();
    expect($history)->toHaveCount(1)
        ->and($history[0]->from_state)->toBeNull()
        ->and($history[0]->to_state)->toBe('draft')
        ->and($history[0]->actor_customer_id)->toBe($this->seller->customer_id)
        ->and(DB::statement('SET CONSTRAINTS ALL IMMEDIATE'))->toBeTrue();

    // Not audited (Part 2 §3): a customer action, recorded in the history.
    expect(DB::table('audit_log')->where('entity_type', 'listing')->count())->toBe(0);
});

it('never returns the storage key of a file', function () {
    $res = createListing($this, $this->seller, Listings::goldBody($this, $this->seller))->assertCreated();

    expect(json_encode($res->json()))->not->toContain('listing-media/')->not->toContain('storage_ref')
        ->and($res->json('data.media.0.url'))->toBe(Listings::SELLER_URL.'/'.$res->json('data.id').'/media/'.$res->json('data.media.0.id'));
});

it('creates a diamond and a gold-with-diamond draft by asking price', function () {
    $diamond = Listings::goldBody($this, $this->seller, [
        'category' => 'diamond', 'piece_type_id' => Listings::pieceTypeId(PieceCategory::DIAMOND),
        'karat_code' => null, 'stated_weight_g' => null, 'making_charge_per_g' => null, 'asking_price' => '120000',
    ], photos: 3);
    $mixed = Listings::goldBody($this, $this->seller, [
        'category' => 'gold_with_diamond', 'piece_type_id' => Listings::pieceTypeId(PieceCategory::GOLD_WITH_DIAMOND),
        'stated_weight_g' => '6.1', 'making_charge_per_g' => null, 'asking_price' => '80150.50',
        'stone_certificate_token' => Listings::uploadToken($this, $this->seller, 'stone_certificate', Listings::pdf('cert.pdf')),
    ], photos: 3);

    createListing($this, $this->seller, $diamond)->assertCreated()
        ->assertJsonPath('data.karat', null)->assertJsonPath('data.stated_weight_g', null)
        ->assertJsonPath('data.asking_price', '120000.0000')->assertJsonPath('data.current_price', '120000.0000')
        ->assertJsonPath('data.price_is_indicative', false);

    createListing($this, $this->seller, $mixed)->assertCreated()
        ->assertJsonPath('data.karat', 21)->assertJsonPath('data.stated_weight_g', '6.100')
        ->assertJsonPath('data.making_charge_per_g', null)->assertJsonPath('data.asking_price', '80150.5000');

    expect(Listing::query()->count())->toBe(2)
        ->and(DB::table('listing_media')->where('kind', 'stone_certificate')->value('is_private'))->toBeFalse();
});

it('refuses a gold piece without its karat or weight', function (string $category, string $missing) {
    $body = Listings::goldBody($this, $this->seller, [
        'category' => $category,
        'piece_type_id' => Listings::pieceTypeId(PieceCategory::from($category)),
        $missing => null,
    ] + ($category === 'gold' ? [] : ['making_charge_per_g' => null, 'asking_price' => '80000']));

    createListing($this, $this->seller, $body)->assertStatus(422)->assertJsonPath('code', 'gold_needs_karat_weight');

    expect(Listing::query()->count())->toBe(0);
})->with([
    ['gold', 'karat_code'], ['gold', 'stated_weight_g'],
    ['gold_with_diamond', 'karat_code'], ['gold_with_diamond', 'stated_weight_g'],
]);

it('refuses a listing without an enabled branch', function (Closure $branches) {
    $body = Listings::goldBody($this, $this->seller, ['branch_option_ids' => $branches()]);

    createListing($this, $this->seller, $body)->assertStatus(422)->assertJsonPath('code', 'branch_options_required');

    expect(Listing::query()->count())->toBe(0)->and(DB::table('listing_media')->count())->toBe(0);
})->with([
    'none' => [fn () => []],
    'a disabled branch' => [fn () => [Branch::factory()->disabled()->create()->branch_id]],
    'an unknown branch' => [fn () => [32000]],
    'one open and one disabled' => [fn () => [Branch::factory()->create()->branch_id, Branch::factory()->disabled()->create()->branch_id]],
]);

it('refuses a listing without the branch list at all', function () {
    createListing($this, $this->seller, Listings::goldBody($this, $this->seller, ['branch_option_ids' => null]))
        ->assertStatus(422)->assertJsonPath('code', 'branch_options_required');
});

it('refuses a listing without the current ownership declaration', function (array $override) {
    DB::table('legal_document')->insert([
        'code' => 'ownership_declaration', 'version' => 2, 'body_en' => 'New text.', 'body_ar' => 'نص جديد.', 'published_by' => SystemActor::id(),
    ]);
    $old = (int) DB::table('legal_document')->where('code', 'ownership_declaration')->where('version', 1)->value('legal_doc_id');

    $body = Listings::goldBody($this, $this->seller, ['ownership_legal_doc_id' => $old]);
    foreach ($override as $key => $value) {
        $body[$key] = $value;
    }

    createListing($this, $this->seller, $body)->assertStatus(422)->assertJsonPath('code', 'ownership_declaration_required');

    expect(Listing::query()->count())->toBe(0)->and(DB::table('agreement_acceptance')->count())->toBe(0);
})->with([
    'an old version, accepted' => [[]],
    'not accepted' => [['ownership_declaration_accepted' => false]],
    'accepted as a string' => [['ownership_declaration_accepted' => 'yes']],
]);

it('refuses an upload token that is not usable here', function (Closure $token, string $field) {
    $body = Listings::goldBody($this, $this->seller);
    $value = $token($this, $this->seller);
    $body[$field] = $field === 'photo_tokens' ? [...$body['photo_tokens'], $value] : $value;

    createListing($this, $this->seller, $body)->assertStatus(422)->assertJsonPath('code', 'upload_token_invalid');

    expect(Listing::query()->count())->toBe(0)->and(DB::table('listing_media')->count())->toBe(0);
})->with([
    'unknown' => [fn () => Str::random(48), 'photo_tokens'],
    'another purpose' => [fn ($t, $c) => Listings::uploadToken($t, $c, 'listing_invoice', Listings::png()), 'photo_tokens'],
    'a photo as the video' => [fn ($t, $c) => Listings::uploadToken($t, $c, 'listing_photo'), 'video_token'],
    'another customer\'s' => [fn ($t) => Listings::uploadToken($t, Customer::factory()->verified()->create(), 'listing_photo'), 'photo_tokens'],
]);

it('uses an upload token once', function () {
    $first = Listings::goldBody($this, $this->seller);
    createListing($this, $this->seller, $first)->assertCreated();

    $second = Listings::goldBody($this, $this->seller, ['photo_tokens' => $first['photo_tokens']]);
    createListing($this, $this->seller, $second)->assertStatus(422)->assertJsonPath('code', 'upload_token_invalid');

    expect(Listing::query()->count())->toBe(1);
});

it('validates the fields of the category', function (Closure $overrides, string $field) {
    $body = Listings::goldBody($this, $this->seller, $overrides());

    createListing($this, $this->seller, $body)->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors($field);

    expect(Listing::query()->count())->toBe(0);
})->with([
    'a piece type of another category' => [fn () => ['piece_type_id' => Listings::pieceTypeId(PieceCategory::DIAMOND)], 'piece_type_id'],
    'a disabled piece type' => [function () {
        PieceType::query()->where('category', 'gold')->where('name_en', 'Ring')->update(['is_enabled' => false]);

        return [];
    }, 'piece_type_id'],
    'a disabled karat' => [fn () => ['karat_code' => 22], 'karat_code'],
    'an unknown karat' => [fn () => ['karat_code' => 19], 'karat_code'],
    'an asking price on gold' => [fn () => ['asking_price' => '1000'], 'asking_price'],
    'gold without a making charge' => [fn () => ['making_charge_per_g' => null], 'making_charge_per_g'],
    'a weight with four decimals' => [fn () => ['stated_weight_g' => '8.0001'], 'stated_weight_g'],
    'a weight of zero' => [fn () => ['stated_weight_g' => '0'], 'stated_weight_g'],
    'a making charge with three decimals' => [fn () => ['making_charge_per_g' => '250.125'], 'making_charge_per_g'],
    'a negative making charge' => [fn () => ['making_charge_per_g' => '-1'], 'making_charge_per_g'],
    'a description of 2001 characters' => [fn () => ['description' => str_repeat('a', 2001)], 'description'],
    'an unknown category' => [fn () => ['category' => 'silver'], 'category'],
    'the same branch twice' => [function () {
        $id = Branch::factory()->create()->branch_id;

        return ['branch_option_ids' => [$id, $id]];
    }, 'branch_option_ids.0'],
]);

it('validates the fields of a diamond', function (array $overrides, string $field) {
    $body = Listings::goldBody($this, $this->seller, [
        'category' => 'diamond', 'piece_type_id' => Listings::pieceTypeId(PieceCategory::DIAMOND),
        'karat_code' => null, 'stated_weight_g' => null, 'making_charge_per_g' => null, 'asking_price' => '120000',
    ], photos: 3);

    createListing($this, $this->seller, array_merge($body, $overrides))->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'a karat' => [['karat_code' => 21], 'karat_code'],
    'a weight' => [['stated_weight_g' => '1.000'], 'stated_weight_g'],
    'a making charge' => [['making_charge_per_g' => '10'], 'making_charge_per_g'],
    'no asking price' => [['asking_price' => null], 'asking_price'],
    'an asking price of zero' => [['asking_price' => '0'], 'asking_price'],
]);

it('enforces the media limits', function () {
    $seven = Listings::goldBody($this, $this->seller, photos: 7);
    createListing($this, $this->seller, $seven)->assertStatus(422)->assertJsonValidationErrors('photo_tokens');

    expect(Listing::query()->count())->toBe(0);
});

it('accepts six photos', function () {
    createListing($this, $this->seller, Listings::goldBody($this, $this->seller, photos: 6))
        ->assertCreated()->assertJsonCount(6, 'data.media');
});

it('refuses customers who may not trade', function () {
    $pending = Customer::factory()->pendingVerification()->create();
    $rejected = Customer::factory()->rejected()->create();
    $suspended = Customer::factory()->suspended(byStaffId: SystemActor::id())->create();
    $body = Listings::goldBody($this, $this->seller);

    createListing($this, $pending, $body)->assertForbidden()->assertJsonPath('code', 'verification_required');
    createListing($this, $rejected, $body)->assertForbidden()->assertJsonPath('code', 'verification_required');
    createListing($this, $suspended, $body)->assertForbidden()->assertJsonPath('code', 'account_suspended');
    Listings::anonymous($this)->postJson(Listings::SELLER_URL, $body, Listings::key())->assertUnauthorized();

    expect(Listing::query()->count())->toBe(0);
});

it('needs an idempotency key and replays the same result', function () {
    $body = Listings::goldBody($this, $this->seller);

    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL, $body)
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

    $key = (string) Str::uuid();
    $first = createListing($this, $this->seller, $body, $key)->assertCreated();
    $replay = createListing($this, $this->seller, $body, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(Listing::query()->count())->toBe(1)
        ->and(DB::table('agreement_acceptance')->count())->toBe(1);

    createListing($this, $this->seller, ['description' => 'Another body entirely, with the same key as before.'] + $body, $key)
        ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_mismatch');
});

it('still accepts a karat that is enabled when others are not', function () {
    Karat::query()->whereKey(18)->update(['is_enabled' => false]);

    createListing($this, $this->seller, Listings::goldBody($this, $this->seller))->assertCreated();
    createListing($this, $this->seller, Listings::goldBody($this, $this->seller, ['karat_code' => 18]))
        ->assertStatus(422)->assertJsonValidationErrors('karat_code');
});
