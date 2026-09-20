<?php

use App\Models\Customer;
use App\Models\CustomerPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('returns account_locked after 5 failed logins on the same phone', function () {
    $customer = Customer::factory()->create(['phone' => '+201000003333']);
    CustomerPassword::query()->create([
        'customer_id' => $customer->customer_id,
        'password_hash' => Hash::make('correct-horse-battery'),
        'password_changed_at' => now(),
    ]);

    $body = [
        'phone' => '+201000003333',
        'password' => 'wrong-password-abc',
    ];

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/customer/auth/login', $body, ['X-Device-Id' => str_repeat('g', 32)])
            ->assertStatus(401);
    }

    $this->postJson('/api/v1/customer/auth/login', $body, ['X-Device-Id' => str_repeat('g', 32)])
        ->assertStatus(429)
        ->assertJsonPath('code', 'account_locked')
        ->assertHeader('Retry-After');
});
