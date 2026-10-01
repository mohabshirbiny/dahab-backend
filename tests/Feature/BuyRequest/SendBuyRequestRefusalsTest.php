<?php

use App\Enums\CustomerStatus;
use App\Models\BuyRequest;
use App\Models\Customer;
use App\Models\LegalDocument;
use App\Models\Listing;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuyRequests;
use Tests\Support\Ledger;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 011 US1 scenarios 3–9; FR-001–FR-008: every refusal records nothing
// and holds nothing.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->live()->create(['seller_id' => $this->seller->customer_id]);
});

function assertNothingHeld(): void
{
    expect(BuyRequest::query()->count())->toBe(0)
        ->and(DB::table('ledger_transaction')->where('event_kind', 'deposit_hold')->count())->toBe(0)
        ->and(DB::table('agreement_acceptance')->where('context', 'buy_request')->count())->toBe(0);
}

it('refuses a short balance with the deposit, the balance and the shortfall', function () {
    $buyer = BuyRequests::funded('1000');
    $price = BuyRequests::price($this, $this->listing);
    $deposit = bcadd(bcadd(bcdiv(bcmul($price, '20', 8), '100', 8), '0.005', 8), '0', 2).'00';

    BuyRequests::send($this, $buyer, $this->listing)->assertStatus(409)
        ->assertJsonPath('code', 'insufficient_funds')
        ->assertJsonPath('details.deposit_amount', $deposit)
        ->assertJsonPath('details.available', '1000.0000')
        ->assertJsonPath('details.shortfall', bcsub($deposit, '1000', 4));

    assertNothingHeld();
    expect($this->listing->fresh()->state->value)->toBe('live');
});

it('refuses a price that moved beyond the tolerance, with the fresh figures', function () {
    $buyer = BuyRequests::funded('50000');
    $price = BuyRequests::price($this, $this->listing);

    BuyRequests::send($this, $buyer, $this->listing, ['confirm_locked_price' => bcmul($price, '0.98', 4)])->assertStatus(409)
        ->assertJsonPath('code', 'price_moved')
        ->assertJsonPath('details.current_price', $price);

    assertNothingHeld();
});

it('refuses a gold piece that cannot be priced', function () {
    // gold_price is append-only: TRUNCATE (rolled back with the test) stands in for "no price yet".
    DB::statement('TRUNCATE gold_price CASCADE');
    $buyer = BuyRequests::funded('50000');

    BuyRequests::send($this, $buyer, $this->listing, ['confirm_locked_price' => '58000'])->assertStatus(409)
        ->assertJsonPath('code', 'price_unavailable');

    assertNothingHeld();
});

it('refuses a second active request from the same buyer', function () {
    $buyer = BuyRequests::funded('50000');
    BuyRequests::queued($this, $buyer, $this->listing);

    BuyRequests::send($this, $buyer, $this->listing)->assertStatus(409)->assertJsonPath('code', 'already_in_queue');

    expect(BuyRequest::query()->count())->toBe(1);
});

it('refuses the seller on their own piece', function () {
    Listings::as($this, $this->seller);
    Ledger::topUp($this->seller, '50000');

    BuyRequests::send($this, $this->seller, $this->listing)->assertStatus(409)->assertJsonPath('code', 'cannot_buy_own_listing');

    assertNothingHeld();
});

it('refuses a piece that is not on the market', function (string $state, int $status, ?string $code) {
    $buyer = BuyRequests::funded('50000');
    $listing = Listing::factory()->withPhotos(2)->{$state}()->create(['seller_id' => $this->seller->customer_id]);

    $res = BuyRequests::send($this, $buyer, $listing, ['confirm_locked_price' => '58000'])->assertStatus($status);
    if ($code !== null) {
        $res->assertJsonPath('code', $code);
    }

    assertNothingHeld();
})->with([
    'draft' => ['draft', 404, null],
    'in review' => ['inReview', 404, null],
    'withdrawn' => ['withdrawn', 409, 'listing_not_purchasable'],
    'on hold' => ['suspendedHold', 409, 'listing_not_purchasable'],
]);

it('refuses missing, old or other deposit terms', function () {
    $buyer = BuyRequests::funded('50000');
    $old = BuyRequests::termsId();
    LegalDocument::query()->create([
        'code' => LegalDocument::DEPOSIT_AGREEMENT, 'version' => 2, 'body_en' => 'v2', 'body_ar' => 'v2', 'published_by' => SystemActor::id(),
    ]);

    BuyRequests::send($this, $buyer, $this->listing, ['deposit_legal_doc_id' => $old])->assertStatus(422)->assertJsonPath('code', 'deposit_agreement_required');
    BuyRequests::send($this, $buyer, $this->listing, ['deposit_legal_doc_id' => Listings::declarationId()])->assertStatus(422)->assertJsonPath('code', 'deposit_agreement_required');
    BuyRequests::send($this, $buyer, $this->listing, ['deposit_legal_doc_id' => null])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    assertNothingHeld();
});

it('refuses customers who may not trade', function () {
    $pending = Customer::factory()->pendingVerification()->create();
    Ledger::topUp($pending, '50000');
    BuyRequests::send($this, $pending, $this->listing)->assertStatus(403)->assertJsonPath('code', 'verification_required');

    $suspended = Customer::factory()->suspended(byStaffId: SystemActor::id())->create();
    Ledger::topUp($suspended, '50000');
    expect($suspended->fresh()->status)->toBe(CustomerStatus::SUSPENDED);
    BuyRequests::send($this, $suspended, $this->listing)->assertStatus(403)->assertJsonPath('code', 'account_suspended');

    assertNothingHeld();
});

it('validates the body', function (array $body) {
    $buyer = BuyRequests::funded('50000');

    BuyRequests::send($this, $buyer, $this->listing, $body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    assertNothingHeld();
})->with([
    'zero price' => [['confirm_locked_price' => '0']],
    'five decimals' => [['confirm_locked_price' => '58000.12345']],
    'negative price' => [['confirm_locked_price' => '-1']],
    'not a uuid' => [['listing_id' => 'abc']],
]);

it('needs an idempotency key', function () {
    $buyer = BuyRequests::funded('50000');

    Listings::as($this, $buyer)->postJson(BuyRequests::BUYER_URL, [
        'listing_id' => $this->listing->listing_id, 'confirm_locked_price' => '58000', 'deposit_legal_doc_id' => BuyRequests::termsId(),
    ])->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

    assertNothingHeld();
});

it('is rate limited per customer', function () {
    $buyer = BuyRequests::funded('50000');
    RateLimiter::clear('customer-buy-requests:'.$buyer->customer_id);
    config(['dahab-buy-requests.send_per_minute' => 2]);

    BuyRequests::send($this, $buyer, $this->listing, ['confirm_locked_price' => '1'])->assertStatus(409);
    BuyRequests::send($this, $buyer, $this->listing, ['confirm_locked_price' => '1'])->assertStatus(409);
    BuyRequests::send($this, $buyer, $this->listing, ['confirm_locked_price' => '1'])->assertStatus(429);
});
