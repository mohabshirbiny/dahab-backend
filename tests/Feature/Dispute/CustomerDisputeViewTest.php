<?php

use App\Enums\SeedRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\Listings;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 FR-007, research R10, analysis C2: the raiser sees their dispute
// and, once resolved, Dahab's reply; the other party sees only that the order
// is frozen and, later, resumed or cancelled — never the dispute itself.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('shows the raiser their dispute through its states and the reply', function () {
    $order = Disputes::awaitingBalance($this);
    $buyer = Orders::buyer($order);
    Orders::show($this, $buyer, $order)->assertOk()
        ->assertJsonPath('data.frozen', false)->assertJsonPath('data.dispute', null)
        ->assertJsonPath('data.dispute_outcome', null);
    expect(Orders::show($this, $buyer, $order)->json('data.actions'))->toContain('report_problem');

    $dispute = Disputes::opened($this, $order);
    $res = Orders::show($this, $buyer, $order)->assertOk()
        ->assertJsonPath('data.frozen', true)
        ->assertJsonPath('data.state', 'disputed')
        ->assertJsonPath('data.dispute.ref', $dispute->dispute_ref)
        ->assertJsonPath('data.dispute.state', 'open')
        ->assertJsonPath('data.dispute.detail', Disputes::DETAIL);
    expect($res->json('data.actions'))->not->toContain('report_problem')->not->toContain('pay');

    $colleague = Orders::staff($this, SeedRole::COO);
    Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::passOn($this, $dispute, ['assignee_id' => $colleague->staff_id, 'note' => 'Please look at the scale photos.'])->assertOk();
    Orders::show($this, $buyer, $order)->assertJsonPath('data.dispute.state', 'being_looked_at')
        ->assertJsonPath('data.dispute.order_ref', $order->order_ref);
    expect(json_encode(Orders::show($this, $buyer, $order)->json()))->not->toContain('scale photos');

    Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::resolve($this, $dispute, ['reply' => 'We weighed it again; the result stands.'])->assertOk();
    Orders::show($this, $buyer, $order)->assertOk()
        ->assertJsonPath('data.frozen', false)
        ->assertJsonPath('data.dispute.state', 'resolved')
        ->assertJsonPath('data.dispute.outcome', 'resume')
        ->assertJsonPath('data.dispute.reply', 'We weighed it again; the result stands.')
        ->assertJsonPath('data.dispute_outcome', 'resumed');
});

it('never shows the other party the dispute, only frozen and the outcome', function () {
    $order = Disputes::awaitingBalance($this);
    $seller = Orders::seller($order);
    $dispute = Disputes::opened($this, $order);

    $res = Orders::show($this, $seller, $order)->assertOk()
        ->assertJsonPath('data.frozen', true)->assertJsonPath('data.dispute', null);
    $json = json_encode($res->json());
    // While the buyer's dispute holds the order, the seller cannot raise their own yet.
    expect($json)->not->toContain($dispute->dispute_ref)->not->toContain('IGI weighed it lower')
        ->and($res->json('data.actions'))->not->toContain('report_problem');

    $list = Listings::as($this, $seller)->getJson(Orders::CUSTOMER_URL)->assertOk();
    expect(collect($list->json('data'))->firstWhere('id', $order->order_id)['frozen'])->toBeTrue();

    Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::resolve($this, $dispute)->assertOk();
    $res = Orders::show($this, $seller, $order)->assertJsonPath('data.dispute_outcome', 'resumed')->assertJsonPath('data.dispute', null);
    expect(json_encode($res->json()))->not->toContain('result stands')
        ->and($res->json('data.actions'))->toContain('report_problem');
});
