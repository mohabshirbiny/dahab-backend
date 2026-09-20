<?php

use App\Models\Customer;
use App\Models\CustomerPassword;
use App\Models\CustomerTrustedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function makeCustomerWithPassword(string $plain = 'correct-horse-battery'): Customer
{
    $customer = Customer::factory()->create(['phone' => '+201000001111']);
    CustomerPassword::query()->create([
        'customer_id' => $customer->customer_id,
        'password_hash' => Hash::make($plain),
        'password_changed_at' => now(),
    ]);

    return $customer;
}

it('signs in with correct phone and password from a trusted device', function () {
    $customer = makeCustomerWithPassword();
    $deviceId = str_repeat('d', 32);
    $fp = hash('sha256', $deviceId.'|web');
    CustomerTrustedDevice::query()->create([
        'customer_id' => $customer->customer_id,
        'fingerprint_hash' => $fp,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/customer/auth/login', [
        'phone' => '+201000001111',
        'password' => 'correct-horse-battery',
    ], ['X-Device-Id' => $deviceId, 'X-Device-Platform' => 'web']);

    $response
        ->assertOk()
        ->assertJsonPath('data.customer.phone', '+201000001111')
        ->assertJsonStructure(['data' => ['session' => ['access_token', 'refresh_token', 'family_id']]])
        ->assertJsonMissing(['otp_required' => true]);

    expect($response->getContent())->not->toContain('password_hash');
});

it('refuses sign-in with wrong password using invalid_credentials', function () {
    makeCustomerWithPassword();

    $response = $this->postJson('/api/v1/customer/auth/login', [
        'phone' => '+201000001111',
        'password' => 'wrong-password-000',
    ], ['X-Device-Id' => str_repeat('e', 32)]);

    $response->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
});

it('refuses sign-in for an unknown phone with the same shape as wrong password', function () {
    $response = $this->postJson('/api/v1/customer/auth/login', [
        'phone' => '+201000009999',
        'password' => 'anything-goes-1234',
    ], ['X-Device-Id' => str_repeat('f', 32)]);

    $response->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
});
