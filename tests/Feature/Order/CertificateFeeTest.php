<?php

use App\Enums\AccountKind;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Package D (Part 3 §5 / OI-3.2): the stone certificate cost the seller named is locked on the
// order at acceptance and taken from their proceeds at pay-balance onto `igi_certificates`.
// The buyer's total, the commission and the VAT do not change.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->seller = Customer::factory()->verified()->create();
});

function paidStone($test, string $kind): array
{
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create([
        'seller_id' => $test->seller->customer_id,
        'certificate_kind' => $kind,
    ]);
    $order = Orders::accepted($test, $diamond, '200000');
    Orders::staff($test, SeedRole::OPERATIONS);
    Orders::receive($test, $order)->assertOk();
    Orders::staff($test, SeedRole::IGI_BRANCH);
    Orders::result($test, $order, ['measured_stone_grade' => 'VS1 G'])->assertCreated();
    Orders::pay($test, Orders::buyer($order), $order->refresh())->assertOk();

    return [$order->refresh(), $diamond];
}

it('locks the certificate cost at acceptance', function () {
    $diamond = Listing::factory()->diamond()->withPhotos(3)->live()->create([
        'seller_id' => $this->seller->customer_id, 'certificate_kind' => 'other_lab',
    ]);

    $order = Orders::accepted($this, $diamond, '200000');

    expect((string) $order->refresh()->certificate_cost)->toBe('1000.0000');
});

it('takes the cost from the seller proceeds onto igi_certificates and leaves the rest alone', function () {
    [$order] = paidStone($this, 'none');

    // The same sale as StoneSettlementTest: 120,000 total, 5,160 commission net; 2,000 less for the certificate.
    expect((string) $order->final_buyer_total)->toBe('120000.0000')
        ->and((string) $order->commission_amount)->toBe('5160.0000')
        ->and((string) $order->seller_proceeds)->toBe(bcsub('120000', bcadd('5160', bcadd((string) $order->vat_amount, '2000', 4), 4), 4))
        ->and(Orders::internal(AccountKind::IGI_CERTIFICATES))->toBe('2000.0000');
});

it('charges nothing for an IGI certificate and nothing before the fee existed', function () {
    [$order] = paidStone($this, 'igi');

    expect((string) $order->seller_proceeds)->toBe(bcsub('120000', bcadd('5160', (string) $order->vat_amount, 4), 4))
        ->and(Orders::internal(AccountKind::IGI_CERTIFICATES))->toBe('0.0000');
});

it('shows the fee on the seller order and refuses a certificate on gold', function () {
    $this->seller = Customer::factory()->verified()->create();
    $gold = Listing::factory()->live()->withPhotos(3)->create(['seller_id' => $this->seller->customer_id]);

    expect($gold->certificate_kind)->toBeNull();

    Listings::as($this, $this->seller)->postJson('/api/v1/customer/me/commission-preview', [
        'category' => 'gold', 'karat' => 21, 'weight_g' => '10.000', 'making_charge_per_g' => '300', 'certificate' => 'none',
    ])->assertStatus(422);
});

it('takes the named certificate out of the quote payout', function () {
    $plain = $this->getJson('/api/v1/reference/quote?category=diamond&asking_price=100000')->assertOk()->json('data');
    $none = $this->getJson('/api/v1/reference/quote?category=diamond&asking_price=100000&certificate=none')->assertOk()->json('data');

    expect($plain['certificate_fee'])->toBe('0.0000')
        ->and($none['certificate_fee'])->toBe('2000.0000')
        ->and(bcsub($plain['payout'], $none['payout'], 4))->toBe('2000.0000');
});
