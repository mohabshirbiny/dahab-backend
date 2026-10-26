<?php

use App\Enums\ListingMediaKind;
use App\Enums\SeedRole;
use App\Models\Listing;
use Database\Factories\ListingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Finance;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Owner notes of 2026-10-26 (docs/features/app-notes-sales.md): what the seller will receive before
// the buyer pays, the order number on every wallet line, the order every compensation names, and
// the stone and its certificate on the inspector's screen.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('shows the seller what the sale will pay before the buyer pays, the same figure the payment posts', function () {
    $order = Orders::inspected($this, Orders::accepted($this, Orders::ring(), '200000'), '10.000');
    $seller = Orders::seller($order);

    $before = Orders::show($this, $seller, $order)->assertOk()->json('data');
    expect($before['seller_proceeds'])->toBeNull()
        ->and($before['seller_estimate'])->not->toBeNull()
        ->and($before['seller_estimate']['weight_measured'])->toBeTrue()
        ->and($before['seller_estimate']['indicative'])->toBeFalse()
        ->and(bcsub(bcsub($before['seller_estimate']['gross_egp'], $before['seller_estimate']['commission_total_egp'], 4), $before['seller_estimate']['certificate_fee_egp'], 4))
        ->toBe($before['seller_estimate']['proceeds_egp']);

    // Never for the buyer, never in a list.
    expect(Orders::show($this, Orders::buyer($order), $order)->json('data.seller_estimate'))->toBeNull();

    Orders::pay($this, Orders::buyer($order), $order)->assertOk();

    $after = Orders::show($this, $seller, $order)->assertOk()->json('data');
    expect($after['seller_estimate'])->toBeNull()
        ->and($after['seller_proceeds'])->toBe($before['seller_estimate']['proceeds_egp']);
});

it('gives every wallet line of an order the order number', function () {
    $order = Orders::inspected($this, Orders::accepted($this, Orders::ring(), '200000'), '10.000');
    Orders::pay($this, Orders::buyer($order), $order)->assertOk();

    foreach ([Orders::buyer($order), Orders::seller($order)] as $customer) {
        $rows = Listings::as($this, $customer)->getJson('/api/v1/customer/me/wallet/transactions')->assertOk()->json('data');
        $payment = collect($rows)->firstWhere('kind', 'balance_payment');
        expect($payment['reference'])->toBe($order->order_ref);
    }
});

it('makes a compensation name one of the customer\'s orders, and lists them for the form', function () {
    $customer = Finance::customer('0');
    $order = Finance::orderOf($this, $customer);
    $other = Orders::accepted($this, Orders::ring());
    Finance::staff($this, SeedRole::FINANCE);

    $list = $this->getJson('/api/v1/dashboard/compensation/orders?customer_id='.$customer->customer_id)->assertOk()->json('data');
    expect($list)->toHaveCount(1)
        ->and($list[0])->toMatchArray(['id' => $order->order_id, 'order_ref' => $order->order_ref, 'party' => 'seller', 'category' => 'gold']);

    Finance::pay($this, $customer, '100', ['order_id' => null])->assertUnprocessable()->assertJsonValidationErrors('order_id');
    Finance::pay($this, $customer, '100', ['order_id' => $other->order_id])->assertUnprocessable()->assertJsonValidationErrors('order_id');
    Finance::pay($this, $customer, '100', ['order_id' => $order->order_id])->assertCreated()->assertJsonPath('data.order.ref', $order->order_ref);

    // The entry carries the order, so the customer's wallet line shows its number.
    $line = Listings::as($this, $customer)->getJson('/api/v1/customer/me/wallet/transactions')->json('data.0');
    expect($line['kind'])->toBe('compensation')->and($line['reference'])->toBe($order->order_ref);

    Finance::staff($this, SeedRole::COO);
    $this->getJson('/api/v1/dashboard/compensation/orders?customer_id='.$customer->customer_id)->assertForbidden();
});

it('shows the inspector the stone as stated and opens its certificate through the order', function () {
    $diamond = Listing::factory()->diamond()->withPhotos(2)->live()->create([
        'certificate_kind' => 'other_lab', 'stone_carat' => '0.70', 'stone_clarity' => 'vs', 'stone_cut' => 'very_good',
    ]);
    $certificate = ListingFactory::attachMedia($diamond, ListingMediaKind::STONE_CERTIFICATE,
        UploadedFile::fake()->createWithContent('cert.pdf', "%PDF-1.4\n%%EOF\n"), 'application/pdf', 0);
    $photo = $diamond->media()->where('kind', 'photo')->first();
    $order = Orders::accepted($this, $diamond, '200000');
    Orders::staff($this, SeedRole::OPERATIONS);
    Orders::receive($this, $order)->assertOk();

    Orders::staff($this, SeedRole::IGI_BRANCH);
    $item = collect($this->getJson('/api/v1/dashboard/inspections/work-list')->assertOk()->json('data'))->firstWhere('order_id', $order->order_id);
    expect($item['stone'])->toBe(['certificate_kind' => 'other_lab', 'carat' => '0.70', 'clarity' => 'vs', 'cut' => 'very_good'])
        ->and($item['certificates'])->toBe([['id' => $certificate->media_id, 'mime' => 'application/pdf']]);

    $file = $this->get("/api/v1/dashboard/orders/{$order->order_id}/certificates/{$certificate->media_id}")->assertOk();
    expect($file->streamedContent())->toStartWith('%PDF')
        ->and($file->headers->get('Cache-Control'))->toContain('no-store');
    // Only the certificate: a photo of the piece is not opened here.
    $this->get("/api/v1/dashboard/orders/{$order->order_id}/certificates/{$photo->media_id}")->assertNotFound();

    Finance::staff($this, SeedRole::FINANCE);
    $this->getJson("/api/v1/dashboard/orders/{$order->order_id}")->assertOk()
        ->assertJsonPath('data.certificates.0.id', $certificate->media_id);

    Orders::staff($this, SeedRole::VERIFICATION);
    $this->get("/api/v1/dashboard/orders/{$order->order_id}/certificates/{$certificate->media_id}")->assertForbidden();
});
