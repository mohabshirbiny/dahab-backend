<?php

use App\Enums\SeedRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 FR-017, research R18, R19: the staff order shows its disputes and
// is marked frozen; the Customer file counts and lists the disputes raised.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
});

it('marks the order frozen in the list and shows its disputes in the detail', function () {
    $order = Disputes::awaitingBalance($this);
    $dispute = Disputes::opened($this, $order);

    Orders::staff($this, SeedRole::OPERATIONS);
    $row = collect($this->getJson(Orders::STAFF_URL.'?group=all')->assertOk()->json('data'))->firstWhere('id', $order->order_id);
    expect($row['frozen'])->toBeTrue()->and($row['has_waiting_extension'])->toBeFalse()
        ->and($row['held'])->not->toBe('0.0000');

    $this->getJson(Orders::STAFF_URL."/{$order->order_id}")->assertOk()
        ->assertJsonPath('data.disputes.0.ref', $dispute->dispute_ref)
        ->assertJsonPath('data.disputes.0.raised_as', 'buyer')
        ->assertJsonPath('data.disputes.0.state', 'open')
        ->assertJsonPath('data.can.handle_dispute', true)
        ->assertJsonPath('data.can.receive', false);
});

it('counts and lists the disputes a customer raised in their file', function () {
    $order = Disputes::awaitingBalance($this);
    $dispute = Disputes::opened($this, $order);
    Orders::staff($this, SeedRole::OPERATIONS);
    Disputes::resolve($this, $dispute)->assertOk();

    Orders::staff($this, SeedRole::CEO);
    $this->getJson('/api/v1/dashboard/customers/'.$order->buyer_id)->assertOk()->assertJsonPath('data.disputes_raised', 1);
    $this->getJson('/api/v1/dashboard/customers/'.$order->seller_id)->assertOk()->assertJsonPath('data.disputes_raised', 0);

    $actions = collect($this->getJson('/api/v1/dashboard/customers/'.$order->buyer_id.'/activity')->assertOk()->json('data'))->pluck('action');
    expect($actions)->toContain('dispute.opened')->toContain('dispute.resolved');
});
