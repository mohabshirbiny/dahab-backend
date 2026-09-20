<?php

use App\Models\CustomerTrustedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('walks register -> me -> refresh -> me -> logout on the customer API', function () {
    $register = registerCustomer(
        ['phone' => '+201000020001'],
        ['X-Device-Id' => str_repeat('s', 32)],
    )->assertCreated();

    $access = $register->json('data.session.access_token');
    $refresh = $register->json('data.session.refresh_token');

    $this->bearer($access)->getJson('/api/v1/customer/auth/me')
        ->assertOk()
        ->assertJsonPath('data.phone', '+201000020001');

    $rotated = $this->bearer($refresh)->postJson('/api/v1/customer/auth/refresh')->assertOk();
    $newAccess = $rotated->json('data.access_token');

    $this->bearer($newAccess)->getJson('/api/v1/customer/auth/me')->assertOk();
    $this->bearer($newAccess)->postJson('/api/v1/customer/auth/logout')->assertNoContent();

    expect(DB::table('personal_access_tokens')->count())->toBe(0);
    $this->bearer($newAccess)->getJson('/api/v1/customer/auth/me')->assertStatus(401);
    $this->bearer($rotated->json('data.refresh_token'))->postJson('/api/v1/customer/auth/refresh')->assertStatus(401);
});

it('signs in on a trusted device and gets a session whose tokens carry the customer abilities', function () {
    registerCustomer(
        ['phone' => '+201000020002', 'preferred_lang' => 'ar'],
        ['X-Device-Id' => str_repeat('t', 32)],
    )->assertCreated();

    expect(CustomerTrustedDevice::query()->count())->toBe(1);

    $login = $this->postJson('/api/v1/customer/auth/login', [
        'phone' => '+201000020002',
        'password' => 'correct-horse-battery',
    ], ['X-Device-Id' => str_repeat('t', 32)])->assertOk();

    $abilities = DB::table('personal_access_tokens')
        ->where('family_id', $login->json('data.session.family_id'))
        ->orderBy('id')
        ->pluck('abilities')
        ->map(fn ($a) => json_decode($a, true))
        ->all();

    expect($abilities)->toBe([['customer:access'], ['customer:refresh']]);
});
