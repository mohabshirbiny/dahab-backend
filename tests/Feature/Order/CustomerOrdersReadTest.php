<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 012 US1, FR-001, research R19: each party reads their own orders; the
// other party is a display reference; each sees only their own figures.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Orders::accepted($this);
    $this->buyer = Orders::buyer($this->order);
    $this->seller = Orders::seller($this->order);
});

it('shows the seller their sale with the branch, the deadline and the cancel action', function () {
    $res = Orders::show($this, $this->seller, $this->order)->assertOk();

    $res->assertJsonPath('data.order_ref', $this->order->order_ref)
        ->assertJsonPath('data.role', 'seller')
        ->assertJsonPath('data.state', 'awaiting_delivery')
        ->assertJsonPath('data.stage', 'bring_piece')
        ->assertJsonPath('data.counterparty_ref', $this->buyer->display_ref)
        ->assertJsonPath('data.locked_total_price', '55631.2500')
        ->assertJsonPath('data.deposit_amount', '11126.2500')
        ->assertJsonPath('data.deadline.kind', 'reach_branch')
        ->assertJsonPath('data.amount_due', null)
        ->assertJsonPath('data.actions', ['cancel'])
        ->assertJsonPath('data.timeline.0.event', 'accepted');

    expect($res->json('data.branch.name_en'))->not->toBeNull()
        ->and($res->json('data.branch.hours'))->toBeArray();
});

it('shows the buyer their purchase without the seller and with no action yet', function () {
    Orders::show($this, $this->buyer, $this->order)->assertOk()
        ->assertJsonPath('data.role', 'buyer')
        ->assertJsonPath('data.counterparty_ref', $this->seller->display_ref)
        ->assertJsonPath('data.seller_proceeds', null)
        ->assertJsonPath('data.actions', []);
});

it('lists my orders by role, newest first, a page at a time', function () {
    $second = Orders::accepted($this, null, '60000', $this->buyer);

    $mine = Listings::as($this, $this->buyer)->getJson(Orders::CUSTOMER_URL.'?role=buyer&per_page=1')->assertOk();
    expect($mine->json('data.0.id'))->toBe($second->order_id)
        ->and($mine->json('meta.next_cursor'))->not->toBeNull();

    $next = Listings::as($this, $this->buyer)->getJson(Orders::CUSTOMER_URL.'?role=buyer&per_page=1&cursor='.$mine->json('meta.next_cursor'))->assertOk();
    expect($next->json('data.0.id'))->toBe($this->order->order_id)
        ->and($next->json('meta.next_cursor'))->toBeNull();

    expect(Listings::as($this, $this->buyer)->getJson(Orders::CUSTOMER_URL.'?role=seller')->assertOk()->json('data'))->toBe([])
        ->and(Listings::as($this, $this->buyer)->getJson(Orders::CUSTOMER_URL.'?group=closed')->assertOk()->json('data'))->toBe([]);
});

it('never shows an order to anyone else', function () {
    $stranger = Customer::factory()->verified()->create();

    Orders::show($this, $stranger, $this->order)->assertNotFound();
    expect(Listings::as($this, $stranger)->getJson(Orders::CUSTOMER_URL)->assertOk()->json('data'))->toBe([]);
});

it('never names the other party', function () {
    foreach ([[$this->seller, $this->buyer], [$this->buyer, $this->seller]] as [$me, $them]) {
        $body = Orders::show($this, $me, $this->order)->assertOk()->getContent();

        expect($body)->not->toContain($them->phone)
            ->and($body)->not->toContain((string) $them->full_name ?: '§never§')
            ->and($body)->not->toContain($them->customer_id);
    }
});

it('needs a verified customer', function () {
    $pending = Customer::factory()->pendingVerification()->create();

    Listings::as($this, $pending)->getJson(Orders::CUSTOMER_URL)->assertForbidden()->assertJsonPath('code', 'verification_required');
});
