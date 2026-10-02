<?php

use App\Models\Customer;
use App\Models\OrderCollection;
use App\Models\SellerReturn;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 T075, FR-001, research R19: no customer-facing order answer carries
// the other party's name, phone or email (a recursive scan of every key and
// value), a list never carries a code, and each code reaches only its owner.

/** Every key and scalar value of a decoded JSON answer. */
function leakWalk(mixed $node, array &$keys, array &$values): void
{
    if (is_array($node)) {
        foreach ($node as $k => $v) {
            if (is_string($k)) {
                $keys[] = $k;
            }
            leakWalk($v, $keys, $values);
        }

        return;
    }
    if ($node !== null) {
        $values[] = (string) $node;
    }
}

/** @return list<string> what of `$other` shows up in `$json` */
function leaksOf(array $json, Customer $other): array
{
    $keys = $values = [];
    leakWalk($json, $keys, $values);
    $found = array_values(array_intersect($keys, ['full_name', 'name', 'phone', 'phone_masked', 'email', 'national_id']));
    foreach (array_filter([$other->full_name, $other->phone, $other->email]) as $secret) {
        foreach ($values as $v) {
            if (str_contains($v, (string) $secret)) {
                $found[] = "value: {$secret}";
            }
        }
    }

    return $found;
}

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();

    // A paid order (the buyer's code) and a declined one (the piece returned with the seller's code).
    $this->paid = Orders::inspected($this, Orders::accepted($this), '10.000');
    Orders::pay($this, Orders::buyer($this->paid), $this->paid)->assertOk();
    $this->returned = Orders::inspected($this, Orders::accepted($this), '10.000', 18);

    $this->collectionCode = DatabaseActor::elevate('maintenance', fn () => OrderCollection::query()->where('order_id', $this->paid->order_id)->sole()->code_encrypted);
    $this->returnCode = DatabaseActor::elevate('maintenance', fn () => SellerReturn::query()->where('order_id', $this->returned->order_id)->sole()->code_encrypted);
});

it('never shows a customer the other party, in a list or a detail', function () {
    foreach ([$this->paid, $this->returned] as $order) {
        foreach ([[Orders::buyer($order), Orders::seller($order)], [Orders::seller($order), Orders::buyer($order)]] as [$me, $other]) {
            $list = Listings::as($this, $me)->getJson(Orders::CUSTOMER_URL)->assertOk()->json();
            $detail = Orders::show($this, $me, $order)->assertOk()->json();

            expect(leaksOf($list, $other))->toBe([])
                ->and(leaksOf($detail, $other))->toBe([])
                ->and($detail['data']['counterparty_ref'])->toBe($other->display_ref);
        }
    }
});

it('never puts a code in a list', function () {
    foreach ([Orders::buyer($this->paid), Orders::seller($this->returned)] as $owner) {
        $body = Listings::as($this, $owner)->getJson(Orders::CUSTOMER_URL)->assertOk()->getContent();

        expect($body)->not->toContain('"collection_code"')->not->toContain('"return_code"')
            ->not->toContain('"'.$this->collectionCode.'"')->not->toContain('"'.$this->returnCode.'"');
    }
});

it('shows each code to its owner only', function () {
    expect(Orders::show($this, Orders::buyer($this->paid), $this->paid)->json('data.collection_code'))->toBe($this->collectionCode)
        ->and(Orders::show($this, Orders::seller($this->paid), $this->paid)->json('data.collection_code'))->toBeNull()
        ->and(Orders::show($this, Orders::seller($this->paid), $this->paid)->getContent())->not->toContain($this->collectionCode)
        ->and(Orders::show($this, Orders::seller($this->returned), $this->returned)->json('data.return_code'))->toBe($this->returnCode)
        ->and(Orders::show($this, Orders::buyer($this->returned), $this->returned)->json('data.return_code'))->toBeNull()
        ->and(Orders::show($this, Orders::buyer($this->returned), $this->returned)->getContent())->not->toContain($this->returnCode);
});

it('never shows a stranger an order', function () {
    $stranger = Customer::factory()->verified()->create();

    Orders::show($this, $stranger, $this->paid)->assertNotFound();
    expect(Listings::as($this, $stranger)->getJson(Orders::CUSTOMER_URL)->assertOk()->json('data'))->toBe([]);
});
