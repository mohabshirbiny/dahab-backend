<?php

use App\Enums\CustomerStatus;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Disputes;
use Tests\Support\Orders;

uses(RefreshDatabase::class);

// Spec 014 FR-016, Clarification: no automatic suspension; an optional
// "Suspend the seller" on an against-the-sale resolution, needing
// customer.suspend, in the same transaction.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Orders::workedPrices();
    $this->order = Disputes::awaitingBalance($this);
    $this->dispute = Disputes::opened($this, $this->order);
});

function suspensionBody(string $reason = 'piece_misrepresented'): array
{
    return ['outcome' => 'against_sale', 'suspend_seller' => ['reason' => $reason, 'note' => 'The piece was plated, not solid gold.']];
}

it('never suspends anyone by itself', function () {
    Orders::staff($this, SeedRole::CEO);
    Disputes::resolve($this, $this->dispute, ['outcome' => 'against_sale'])->assertOk();

    expect(Orders::seller($this->order)->status)->toBe(CustomerStatus::ACTIVE);
});

it('suspends the seller with the resolution when asked by someone holding customer.suspend', function () {
    // A second live listing of the seller leaves the market with them.
    $other = Orders::ring(Orders::seller($this->order));

    Orders::staff($this, SeedRole::CEO);
    Disputes::resolve($this, $this->dispute, suspensionBody())->assertOk();

    $seller = Orders::seller($this->order);
    expect($seller->status)->toBe(CustomerStatus::SUSPENDED)
        ->and($seller->suspended_reason->value)->toBe('piece_misrepresented')
        ->and($seller->suspended_note)->toBe('The piece was plated, not solid gold.')
        ->and(DatabaseActor::elevate('maintenance', fn () => DB::table('listing')->where('listing_id', $other->listing_id)->value('state')))->toBe('suspended_hold')
        ->and($this->order->refresh()->state->value)->toBe('cancelled_inspection');
});

it('refuses the suspension without customer.suspend, resolving nothing', function () {
    Orders::staff($this, SeedRole::FINANCE);
    Disputes::resolve($this, $this->dispute, suspensionBody())->assertForbidden()->assertJsonPath('code', 'permission_denied');

    expect($this->order->refresh()->state->value)->toBe('disputed')
        ->and(Orders::seller($this->order)->status)->toBe(CustomerStatus::ACTIVE);
});

it('refuses a suspension with resume, or with an unknown reason', function () {
    Orders::staff($this, SeedRole::CEO);
    Disputes::resolve($this, $this->dispute, ['outcome' => 'resume', 'suspend_seller' => ['reason' => 'repeated_disputes']])
        ->assertStatus(422)->assertJsonValidationErrors('suspend_seller');
    Disputes::resolve($this, $this->dispute, suspensionBody('repeated_cancellations'))->assertStatus(422)->assertJsonValidationErrors('suspend_seller.reason');

    expect($this->order->refresh()->state->value)->toBe('disputed');
});
