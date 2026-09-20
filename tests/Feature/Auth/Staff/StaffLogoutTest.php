<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\AuditEvent;
use App\Enums\StaffRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function staffSession(Staff $staff)
{
    return app(IssueTokenFamilyAction::class)->forStaff($staff);
}

it('revokes the current access + refresh pair on logout', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->create();
    $session = staffSession($staff);

    $this->bearer($session->accessToken)->postJson('/api/v1/dashboard/auth/logout')->assertNoContent();

    $this->bearer($session->accessToken)->getJson('/api/v1/dashboard/auth/me')->assertStatus(401);
    expect(DB::table('personal_access_tokens')->where('family_id', $session->familyId)->count())->toBe(0);
});

it('only revokes the presented family on logout', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->create();
    $laptop = staffSession($staff);
    $phone = staffSession($staff);

    $this->bearer($laptop->accessToken)->postJson('/api/v1/dashboard/auth/logout')->assertNoContent();

    $this->bearer($phone->accessToken)->getJson('/api/v1/dashboard/auth/me')->assertOk();
});

it('revokes every token of the staff member on logout-all and no one else\'s', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->create();
    $colleague = Staff::factory()->role(StaffRole::OPERATIONS)->create();
    $a = staffSession($staff);
    $b = staffSession($staff);
    $colleagueSession = staffSession($colleague);

    $this->bearer($a->accessToken)->postJson('/api/v1/dashboard/auth/logout-all')->assertNoContent();

    expect(DB::table('personal_access_tokens')->where('family_id', $a->familyId)->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('family_id', $b->familyId)->count())->toBe(0);
    $this->bearer($b->accessToken)->getJson('/api/v1/dashboard/auth/me')->assertStatus(401);
    $this->bearer($colleagueSession->accessToken)->getJson('/api/v1/dashboard/auth/me')->assertOk();
});

it('audits the revocation against the staff actor', function () {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->create();
    $session = staffSession($staff);

    $this->bearer($session->accessToken)->postJson('/api/v1/dashboard/auth/logout-all')->assertNoContent();

    $row = AuditLog::query()->where('action', AuditEvent::TOKEN_LOGOUT_ALL->value)->sole();
    expect($row->actor_staff_id)->toBe($staff->staff_id);
});

it('requires authentication', function (string $path) {
    $this->postJson($path)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
})->with(['logout' => '/api/v1/dashboard/auth/logout', 'logout-all' => '/api/v1/dashboard/auth/logout-all']);

it('treats a customer token as unauthenticated', function (string $path) {
    $customer = Customer::factory()->create();
    $token = app(IssueTokenFamilyAction::class)->forCustomer($customer)->accessToken;

    $this->bearer($token)->postJson($path)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
})->with(['logout' => '/api/v1/dashboard/auth/logout', 'logout-all' => '/api/v1/dashboard/auth/logout-all']);

it('refuses a refresh token with 403 forbidden', function (string $path) {
    $staff = Staff::factory()->role(StaffRole::OPERATIONS)->create();
    $session = staffSession($staff);

    $this->bearer($session->refreshToken)->postJson($path)->assertStatus(403)->assertJsonPath('code', 'forbidden');
    // The refused call revoked nothing.
    expect(DB::table('personal_access_tokens')->where('family_id', $session->familyId)->count())->toBe(2);
})->with(['logout' => '/api/v1/dashboard/auth/logout', 'logout-all' => '/api/v1/dashboard/auth/logout-all']);
