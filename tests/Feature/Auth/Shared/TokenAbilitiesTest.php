<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Staff;
use App\Support\SessionDto;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
});

/**
 * One session for the requested principal plus the endpoints that exercise it.
 *
 * @return array{0: SessionDto, 1: string, 2: string, 3: Customer|Staff}
 */
function abilityFixture(string $kind): array
{
    $issue = app(IssueTokenFamilyAction::class);

    if ($kind === 'customer') {
        $customer = Customer::factory()->verified()->create();

        return [$issue->forCustomer($customer), '/api/v1/customer/auth/me', '/api/v1/customer/auth/refresh', $customer];
    }

    $staff = Staff::factory()->role(SeedRole::OPERATIONS)->create();

    return [$issue->forStaff($staff), '/api/v1/dashboard/auth/me', '/api/v1/dashboard/auth/refresh', $staff];
}

function abilitiesOf(string $plainTextToken): array
{
    return PersonalAccessToken::findToken($plainTextToken)->abilities;
}

it('mints <kind>:access on the access token and <kind>:refresh on the refresh token, nothing else', function (string $kind) {
    [$session] = abilityFixture($kind);

    expect(abilitiesOf($session->accessToken))->toBe(["{$kind}:access"])
        ->and(abilitiesOf($session->refreshToken))->toBe(["{$kind}:refresh"]);

    $rows = DB::table('personal_access_tokens')->where('family_id', $session->familyId)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('actor_kind')->unique()->all())->toBe([$kind]);
})->with(['customer', 'staff']);

it('never hands out a wildcard or a cross-principal ability', function () {
    [$customerSession] = abilityFixture('customer');
    [$staffSession] = abilityFixture('staff');

    $abilities = collect([$customerSession, $staffSession])
        ->flatMap(fn (SessionDto $s) => [...abilitiesOf($s->accessToken), ...abilitiesOf($s->refreshToken)]);

    expect($abilities->all())->not->toContain('*')
        ->and(abilitiesOf($customerSession->accessToken))->not->toContain('staff:access')
        ->and(abilitiesOf($staffSession->accessToken))->not->toContain('customer:access');
});

it('accepts the access token on the access endpoint', function (string $kind) {
    [$session, $me] = abilityFixture($kind);

    $this->bearer($session->accessToken)->getJson($me)->assertOk();
})->with(['customer', 'staff']);

it('refuses a refresh token on an access endpoint (403) and leaves the family intact', function (string $kind) {
    [$session, $me] = abilityFixture($kind);

    $this->bearer($session->refreshToken)->getJson($me)
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');

    expect(DB::table('personal_access_tokens')->where('family_id', $session->familyId)->count())->toBe(2);
})->with(['customer', 'staff']);

it('refuses a refresh token on customer logout and logout-all without revoking anything', function () {
    [$session] = abilityFixture('customer');

    $this->bearer($session->refreshToken)->postJson('/api/v1/customer/auth/logout')->assertStatus(403);
    $this->bearer($session->refreshToken)->postJson('/api/v1/customer/auth/logout-all')->assertStatus(403);

    expect(DB::table('personal_access_tokens')->where('family_id', $session->familyId)->count())->toBe(2);
});

it('refuses an access token on the refresh endpoint (403) and does not rotate it', function (string $kind) {
    [$session, , $refresh] = abilityFixture($kind);

    $this->bearer($session->accessToken)->postJson($refresh)
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');

    expect(DB::table('personal_access_tokens')->whereNotNull('rotated_at')->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('family_id', $session->familyId)->count())->toBe(2);
})->with(['customer', 'staff']);

it('rotates the pair on refresh, keeps the family, and gives the new tokens the right abilities', function (string $kind) {
    [$session, $me, $refresh, $actor] = abilityFixture($kind);

    $response = $this->bearer($session->refreshToken)->postJson($refresh)
        ->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.family_id', $session->familyId);

    $newAccess = $response->json('data.access_token');
    $newRefresh = $response->json('data.refresh_token');

    expect($newAccess)->not->toBe($session->accessToken)
        ->and($newRefresh)->not->toBe($session->refreshToken)
        ->and(abilitiesOf($newAccess))->toBe(["{$kind}:access"])
        ->and(abilitiesOf($newRefresh))->toBe(["{$kind}:refresh"])
        ->and(PersonalAccessToken::findToken($newAccess)->family_id)->toBe($session->familyId);

    // The previous access token is retired; the new one works.
    $this->bearer($session->accessToken)->getJson($me)->assertStatus(401);
    $this->bearer($newAccess)->getJson($me)->assertOk();

    $row = AuditLog::query()->where('action', AuditEvent::TOKEN_ROTATED->value)->sole();
    expect($kind === 'customer' ? $row->actor_customer_id : $row->actor_staff_id)->toBe($actor->getKey());
})->with(['customer', 'staff']);

it('treats a replayed refresh token as theft: 401 refresh_invalid and the whole family is revoked', function (string $kind) {
    [$session, $me, $refresh] = abilityFixture($kind);

    $rotated = $this->bearer($session->refreshToken)->postJson($refresh)->assertOk();
    $newAccess = $rotated->json('data.access_token');
    $newRefresh = $rotated->json('data.refresh_token');

    $this->bearer($session->refreshToken)->postJson($refresh)
        ->assertStatus(401)
        ->assertJsonPath('code', 'refresh_invalid');

    expect(DB::table('personal_access_tokens')->where('family_id', $session->familyId)->count())->toBe(0);

    $this->bearer($newAccess)->getJson($me)->assertStatus(401);
    $this->bearer($newRefresh)->postJson($refresh)->assertStatus(401);
})->with(['customer', 'staff']);

it('keeps another family of the same actor alive when one family is burned', function () {
    $customer = Customer::factory()->verified()->create();
    $issue = app(IssueTokenFamilyAction::class);
    $burned = $issue->forCustomer($customer);
    $other = $issue->forCustomer($customer);

    $this->bearer($burned->refreshToken)->postJson('/api/v1/customer/auth/refresh')->assertOk();
    $this->bearer($burned->refreshToken)->postJson('/api/v1/customer/auth/refresh')->assertStatus(401);

    $this->bearer($other->accessToken)->getJson('/api/v1/customer/auth/me')->assertOk();
});

it('signs out through the customer access token', function () {
    [$session] = abilityFixture('customer');

    $this->bearer($session->accessToken)->postJson('/api/v1/customer/auth/logout')->assertNoContent();

    expect(DB::table('personal_access_tokens')->where('family_id', $session->familyId)->count())->toBe(0);
});

it('does not accept an expired refresh token', function (string $kind) {
    [$session, , $refresh] = abilityFixture($kind);

    $this->travel(config('dahab-auth.refresh_ttl_days') + 1)->days();

    $this->bearer($session->refreshToken)->postJson($refresh)->assertStatus(401);
})->with(['customer', 'staff']);
