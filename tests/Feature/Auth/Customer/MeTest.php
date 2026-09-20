<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns the authenticated customer with derived trade_allowed=false', function () {
    $customer = Customer::factory()->pendingVerification()->create(['phone' => '+201000002222']);
    Sanctum::actingAs($customer, ['customer:access'], 'customer');

    $response = $this->getJson('/api/v1/customer/auth/me');

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $customer->customer_id)
        ->assertJsonPath('data.phone', '+201000002222')
        ->assertJsonPath('data.is_verified', false)
        ->assertJsonPath('data.is_suspended', false)
        ->assertJsonPath('data.trade_allowed', false);

    expect($response->getContent())->not->toContain('password_hash');
});

it('returns 401 unauthenticated without a token', function () {
    $this->getJson('/api/v1/customer/auth/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

it('reports trade_allowed=true for a verified, non-suspended customer', function () {
    $customer = Customer::factory()->verified()->create();
    Sanctum::actingAs($customer, ['customer:access'], 'customer');

    $this->getJson('/api/v1/customer/auth/me')
        ->assertOk()
        ->assertJsonPath('data.trade_allowed', true);
});
