<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Support\DatabaseActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Orders;
use Tests\Support\Promo;

uses(RefreshDatabase::class);

// Spec 023 T047 + T050: staff enable and revoke the advance (first_sale.manage), and a piece that
// carried an advance keeps its price in every state (spec 022 coupling, D23-4).

beforeEach(function () {
    Storage::fake('identity_private');
    Orders::workedPrices();
    $this->seller = Customer::factory()->verified()->create();
    $this->url = "/api/v1/dashboard/customers/{$this->seller->customer_id}/first-sale-advance";
});

it('enables, refuses a second open one, and revokes with an audit row each', function () {
    $staff = Orders::staff($this, SeedRole::FINANCE);

    $this->postJson($this->url, ['reason' => 'short'], Promo::key())->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $this->postJson($this->url, ['reason' => 'Seen the piece and the invoice in person'], Promo::key())->assertCreated()
        ->assertJsonPath('data.seller.display_ref', $this->seller->display_ref);
    $this->postJson($this->url, ['reason' => 'Seen the piece and the invoice in person'], Promo::key())
        ->assertStatus(409)->assertJsonPath('code', 'first_sale_enablement_open');

    // The Customer file shows the state to a holder of the permission.
    $this->getJson("/api/v1/dashboard/customers/{$this->seller->customer_id}")->assertOk()
        ->assertJsonPath('data.first_sale.state', 'enabled');

    $this->deleteJson($this->url, [], Promo::key())->assertOk();
    $this->deleteJson($this->url, [], Promo::key())->assertStatus(404)->assertJsonPath('code', 'first_sale_enablement_not_found');

    $actions = DatabaseActor::elevate('maintenance', fn () => AuditLog::query()->whereIn('action', ['first_sale.enabled', 'first_sale.revoked'])->get());
    expect($actions->pluck('action')->sort()->values()->all())->toBe(['first_sale.enabled', 'first_sale.revoked'])
        ->and($actions->pluck('actor_staff_id')->unique()->all())->toBe([$staff->staff_id]);
});

it('is for first_sale.manage only', function () {
    Orders::staff($this, SeedRole::OPERATIONS);
    $this->postJson($this->url, ['reason' => 'Seen the piece and the invoice in person'], Promo::key())->assertForbidden();
    $this->getJson('/api/v1/dashboard/first-sale-advances')->assertForbidden();

    $this->getJson("/api/v1/dashboard/customers/{$this->seller->customer_id}")->assertJsonMissingPath('data.first_sale');
});
