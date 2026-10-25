<?php

use App\Enums\PieceCategory;
use App\Models\Customer;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// docs/features/offer-details-and-invoices.md §2: a stone piece states its carat, clarity and cut,
// and seller, buyer and staff all see them with the certificate it comes with (or not).

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
});

it('keeps a diamond\'s stone details and shows them to the seller', function () {
    $body = Listings::goldBody($this, $this->seller, [
        'category' => 'diamond', 'piece_type_id' => Listings::pieceTypeId(PieceCategory::DIAMOND),
        'karat_code' => null, 'stated_weight_g' => null, 'making_charge_per_g' => null, 'asking_price' => '90000.00',
        'certificate_kind' => 'none', 'stone_carat' => '0.7', 'stone_clarity' => 'vs', 'stone_cut' => 'very_good',
    ]);

    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL, $body, Listings::key())->assertCreated()
        ->assertJsonPath('data.stone', ['certificate_kind' => 'none', 'carat' => '0.70', 'clarity' => 'vs', 'cut' => 'very_good'])
        ->assertJsonPath('data.certificate_fee', '2000.0000');
});

it('refuses stone details on gold and an unknown clarity', function () {
    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL, Listings::goldBody($this, $this->seller, ['stone_carat' => '1.00']), Listings::key())
        ->assertStatus(422)->assertJsonValidationErrors('stone_carat');

    $body = Listings::goldBody($this, $this->seller, [
        'category' => 'gold_with_diamond', 'piece_type_id' => Listings::pieceTypeId(PieceCategory::GOLD_WITH_DIAMOND),
        'making_charge_per_g' => null, 'asking_price' => '50000.00', 'stone_clarity' => 'flawless',
    ]);
    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL, $body, Listings::key())
        ->assertStatus(422)->assertJsonValidationErrors('stone_clarity');
});

it('shows a buyer on the market and on the order that the stone comes without a certificate', function () {
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create([
        'seller_id' => $this->seller->customer_id, 'certificate_kind' => 'none',
        'stone_carat' => '1.10', 'stone_clarity' => 'si', 'stone_cut' => 'good',
    ]);
    $stone = ['certificate_kind' => 'none', 'carat' => '1.10', 'clarity' => 'si', 'cut' => 'good'];

    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$diamond->listing_id}")->assertOk()->assertJsonPath('data.stone', $stone);
    expect(collect(Listings::anonymous($this)->getJson(Listings::MARKET_URL)->json('data'))->firstWhere('id', $diamond->listing_id)['stone'])->toBe($stone);

    $order = Orders::accepted($this, $diamond, '200000');
    Orders::show($this, Orders::buyer($order), $order)->assertOk()->assertJsonPath('data.piece.stone', $stone);
    Orders::show($this, $this->seller, $order)->assertOk()->assertJsonPath('data.piece.stone', $stone);
});

it('gives gold no stone block', function () {
    $ring = Orders::ring($this->seller);

    Listings::anonymous($this)->getJson(Listings::MARKET_URL."/{$ring->listing_id}")->assertOk()->assertJsonPath('data.stone', null);
});
