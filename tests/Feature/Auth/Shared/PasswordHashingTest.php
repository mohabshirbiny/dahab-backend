<?php

use App\Models\Customer;
use App\Models\CustomerPassword;
use App\Models\CustomerTrustedDevice;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('is configured for Argon2id', function () {
    expect(config('hashing.driver'))->toBe('argon2id')
        ->and(Hash::getDefaultDriver())->toBe('argon2id')
        ->and(Hash::make('correct-horse-battery'))->toStartWith('$argon2id$')
        ->and(password_get_info(Hash::make('x'))['algoName'])->toBe('argon2id');
});

it('stores a registered customer password as Argon2id', function () {
    registerCustomer(
        ['phone' => '+201000010001'],
        ['X-Device-Id' => str_repeat('a', 32)],
    )->assertCreated();

    $hash = CustomerPassword::query()->sole()->password_hash;

    expect($hash)->toStartWith('$argon2id$')
        ->and(Hash::check('correct-horse-battery', $hash))->toBeTrue()
        ->and(Hash::check('wrong-password', $hash))->toBeFalse();
});

it('stores staff passwords as Argon2id', function () {
    $staff = Staff::factory()->withPassword('correct-horse-battery')->create();

    expect($staff->password->password_hash)->toStartWith('$argon2id$');
});

it('still accepts a legacy bcrypt hash and rewrites it as Argon2id on sign-in', function () {
    $customer = Customer::factory()->create(['phone' => '+201000010002']);
    $legacy = password_hash('correct-horse-battery', PASSWORD_BCRYPT);
    DB::table('customer_password')->insert([
        'customer_id' => $customer->customer_id,
        'password_hash' => $legacy,
        'password_changed_at' => now(),
    ]);

    $deviceId = str_repeat('l', 32);
    CustomerTrustedDevice::query()->create([
        'customer_id' => $customer->customer_id,
        'fingerprint_hash' => hash('sha256', $deviceId.'|web'),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    expect($legacy)->toStartWith('$2y$');

    $this->postJson('/api/v1/customer/auth/login', [
        'phone' => '+201000010002',
        'password' => 'correct-horse-battery',
    ], ['X-Device-Id' => $deviceId, 'X-Device-Platform' => 'web'])->assertOk();

    $upgraded = CustomerPassword::query()->where('customer_id', $customer->customer_id)->value('password_hash');

    expect($upgraded)->toStartWith('$argon2id$')
        ->and(Hash::check('correct-horse-battery', $upgraded))->toBeTrue();
});

it('refuses a wrong password against a legacy bcrypt hash', function () {
    $customer = Customer::factory()->create(['phone' => '+201000010003']);
    DB::table('customer_password')->insert([
        'customer_id' => $customer->customer_id,
        'password_hash' => password_hash('correct-horse-battery', PASSWORD_BCRYPT),
        'password_changed_at' => now(),
    ]);

    $this->postJson('/api/v1/customer/auth/login', [
        'phone' => '+201000010003',
        'password' => 'wrong-password-abc',
    ], ['X-Device-Id' => str_repeat('m', 32)])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');

    expect(CustomerPassword::query()->value('password_hash'))->toStartWith('$2y$');
});
