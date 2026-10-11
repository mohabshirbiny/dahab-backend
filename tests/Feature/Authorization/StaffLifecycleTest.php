<?php

use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Staff;
use App\Models\StaffPassword;
use App\Models\StaffRoleModel;
use App\Notifications\StaffPasswordLinkNotification;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Spec 020 US3: create, disable and enable staff accounts (permission staff.manage).

const SLC_STAFF = '/api/v1/dashboard/staff';
const SLC_LOGIN = '/api/v1/dashboard/auth/login';

beforeEach(function () {
    Notification::fake();
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->withPassword('ceo-passphrase-123')->create(['full_name' => 'Founder CEO']);
    $this->token = staffAccessToken($this->ceo);
});

function slcPost($test, string $token, string $path, array $body = [], ?string $key = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($token)->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])->postJson($path, $body);
}

function slcNew(array $overrides = []): array
{
    return array_merge([
        'full_name' => 'Amr Salah',
        'email' => 'amr@dahab.test',
        'phone' => '+201000000009',
        'roles' => ['operations'],
        'reason' => 'New hire, Cairo branch',
    ], $overrides);
}

/** The link last mailed to `$email`: [origin, token]. */
function slcLink(string $email): array
{
    $mine = null;
    Notification::assertSentOnDemand(StaffPasswordLinkNotification::class,
        function (StaffPasswordLinkNotification $n, array $channels, AnonymousNotifiable $to) use ($email, &$mine) {
            if (($to->routes['mail'] ?? null) !== $email) {
                return false;
            }
            $mine = $n;

            return true;
        });
    parse_str((string) parse_url($mine->link, PHP_URL_QUERY), $query);

    return [$mine->origin, $query['token']];
}

/** A member who holds `staff.manage` only (not a CEO): for the bounded-escalation cases. */
function slcStaffAdmin(): Staff
{
    $role = StaffRoleModel::query()->firstOrCreate(
        ['name' => 'staff_admin', 'guard_name' => Staff::GUARD],
        ['display_name' => 'Staff admin', 'requires_mfa' => false],
    );
    $role->givePermissionTo('staff.manage');

    return Staff::factory()->withRole('staff_admin')->withPassword('admin-passphrase-123')->create();
}

it('creates a member without a password, mails the invitation and audits it', function () {
    $res = slcPost($this, $this->token, SLC_STAFF, slcNew(['is_founder' => true, 'password' => 'sneaky-passphrase-1']))
        ->assertCreated()
        ->assertJsonPath('data.email', 'amr@dahab.test')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.is_founder', false)
        ->assertJsonPath('data.password_set', false)
        ->assertJsonPath('data.roles', [['name' => 'operations', 'display_name' => 'Operations']]);

    $member = Staff::query()->findOrFail($res->json('data.id'));
    expect($member->created_by)->toBe($this->ceo->staff_id)
        ->and(StaffPassword::query()->whereKey($member->staff_id)->exists())->toBeFalse()
        ->and($member->is_founder)->toBeFalse();

    [$origin, $token] = slcLink('amr@dahab.test');
    expect($origin)->toBe('invitation')->and($token)->not->toBeEmpty();
    expect(DB::table('one_time_token')->where('actor_staff_id', $member->staff_id)->value('expires_at'))->not->toBeNull();

    $audit = AuditLog::query()->where('action', AuditEvent::STAFF_CREATED->value)->sole();
    expect($audit->actor_staff_id)->toBe($this->ceo->staff_id)
        ->and($audit->entity_id)->toBe($member->staff_id)
        ->and($audit->reason)->toBe('New hire, Cairo branch')
        ->and($audit->after_json['roles'])->toBe(['operations']);
});

it('cannot sign in until the link is used, then signs in with the chosen password', function () {
    $id = slcPost($this, $this->token, SLC_STAFF, slcNew())->assertCreated()->json('data.id');

    $this->withoutToken()->postJson(SLC_LOGIN, ['email' => 'amr@dahab.test', 'password' => 'anything-at-all-1'])
        ->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');

    [, $token] = slcLink('amr@dahab.test');
    $this->withoutToken()->postJson('/api/v1/dashboard/auth/password/reset-confirm', [
        'token' => $token, 'password' => 'member-passphrase-77', 'password_confirmation' => 'member-passphrase-77',
    ])->assertOk();

    $this->withoutToken()->postJson(SLC_LOGIN, ['email' => 'amr@dahab.test', 'password' => 'member-passphrase-77'])
        ->assertOk()->assertJsonPath('data.staff.id', $id);
});

it('needs staff.manage', function () {
    $coo = Staff::factory()->role(SeedRole::COO)->create();

    slcPost($this, staffAccessToken($coo), SLC_STAFF, slcNew())->assertForbidden()->assertJsonPath('code', 'permission_denied');
    slcPost($this, staffAccessToken($coo), SLC_STAFF.'/'.$this->ceo->staff_id.'/disable', ['reason' => 'No right to'])->assertForbidden();
});

it('refuses a missing reason, a duplicate email, no roles and a disabled branch', function () {
    slcPost($this, $this->token, SLC_STAFF, slcNew(['reason' => null]))->assertStatus(422)->assertJsonPath('code', 'reason_required');
    slcPost($this, $this->token, SLC_STAFF, slcNew(['reason' => 'abc']))->assertStatus(422)->assertJsonPath('code', 'reason_required');

    slcPost($this, $this->token, SLC_STAFF, slcNew(['email' => $this->ceo->email]))
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('email');
    slcPost($this, $this->token, SLC_STAFF, slcNew(['email' => strtoupper($this->ceo->email)]))->assertStatus(422);

    slcPost($this, $this->token, SLC_STAFF, slcNew(['roles' => []]))->assertStatus(422)->assertJsonValidationErrors('roles');
    slcPost($this, $this->token, SLC_STAFF, slcNew(['roles' => ['no_such_role']]))->assertStatus(422);

    $off = Branch::factory()->create(['is_enabled' => false]);
    slcPost($this, $this->token, SLC_STAFF, slcNew(['branch_id' => $off->branch_id]))->assertStatus(422)->assertJsonValidationErrors('branch_id');

    expect(Staff::query()->where('email', 'amr@dahab.test')->exists())->toBeFalse();
});

it('assigns the branch it is given', function () {
    $branch = Branch::factory()->create(['is_enabled' => true]);

    slcPost($this, $this->token, SLC_STAFF, slcNew(['roles' => ['igi_branch'], 'branch_id' => $branch->branch_id]))
        ->assertCreated()->assertJsonPath('data.branch_id', $branch->branch_id);
});

it('bounds the roles a non-CEO holder may grant by the permissions they hold', function () {
    $admin = slcStaffAdmin();

    slcPost($this, staffAccessToken($admin), SLC_STAFF, slcNew(['roles' => ['ceo']]))
        ->assertForbidden()->assertJsonPath('code', 'escalation_denied');

    expect(Staff::query()->where('email', 'amr@dahab.test')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditEvent::ESCALATION_DENIED->value)->where('actor_staff_id', $admin->staff_id)->exists())->toBeTrue();
});

it('disables a member: tokens revoked, sign-in refused, history keeps the name, listed as disabled', function () {
    $member = Staff::factory()->role(SeedRole::OPERATIONS)->withPassword('member-passphrase-77')->create(['full_name' => 'Nour Member']);
    $memberToken = staffAccessToken($member);
    $this->bearer($memberToken)->getJson('/api/v1/dashboard/auth/me')->assertOk();

    slcPost($this, $this->token, SLC_STAFF."/{$member->staff_id}/disable", ['reason' => 'Left the company'])
        ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.full_name', 'Nour Member');

    app('auth')->forgetGuards();
    $this->withToken($memberToken)->getJson('/api/v1/dashboard/auth/me')->assertUnauthorized();
    $this->withoutToken()->postJson(SLC_LOGIN, ['email' => $member->email, 'password' => 'member-passphrase-77'])
        ->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');

    $disabled = $this->bearer($this->token)->getJson(SLC_STAFF.'?status=disabled')->assertOk();
    expect(collect($disabled->json('data'))->pluck('id')->all())->toBe([$member->staff_id]);
    $active = $this->bearer($this->token)->getJson(SLC_STAFF.'?status=active')->assertOk();
    expect(collect($active->json('data'))->pluck('id')->all())->not->toContain($member->staff_id);
    $this->bearer($this->token)->getJson(SLC_STAFF.'?status=bogus')->assertStatus(422);

    $audit = AuditLog::query()->where('action', AuditEvent::STAFF_DISABLED->value)->sole();
    expect($audit->reason)->toBe('Left the company')->and($audit->before_json['is_active'])->toBeTrue();
});

it('refuses to disable yourself, a founder, the system actor and an already disabled member', function () {
    $other = Staff::factory()->role(SeedRole::CEO)->founder()->create();
    $system = Staff::query()->where('is_system', true)->sole();
    $gone = Staff::factory()->role(SeedRole::OPERATIONS)->disabled()->create();

    slcPost($this, $this->token, SLC_STAFF."/{$this->ceo->staff_id}/disable", ['reason' => 'Myself please'])
        ->assertForbidden()->assertJsonPath('code', 'escalation_denied');
    slcPost($this, $this->token, SLC_STAFF."/{$other->staff_id}/disable", ['reason' => 'Another founder'])
        ->assertForbidden()->assertJsonPath('code', 'founder_protected');
    slcPost($this, $this->token, SLC_STAFF."/{$system->staff_id}/disable", ['reason' => 'The system actor'])->assertNotFound();
    slcPost($this, $this->token, SLC_STAFF.'/'.Str::uuid().'/disable', ['reason' => 'Nobody at all'])->assertNotFound();
    slcPost($this, $this->token, SLC_STAFF."/{$gone->staff_id}/disable", ['reason' => 'Twice over'])
        ->assertStatus(409)->assertJsonPath('code', 'staff_already_disabled');

    $live = Staff::factory()->role(SeedRole::OPERATIONS)->create();
    slcPost($this, $this->token, SLC_STAFF."/{$live->staff_id}/disable", [])->assertStatus(422)->assertJsonPath('code', 'reason_required');
    expect($live->fresh()->is_active)->toBeTrue();
});

it('keeps the last active role manager', function () {
    // No founder CEO here: the target is the only member who can manage roles.
    $this->ceo->forceFill(['is_active' => false])->save();
    $manager = Staff::factory()->role(SeedRole::COO)->create();
    $admin = slcStaffAdmin();

    slcPost($this, staffAccessToken($admin), SLC_STAFF."/{$manager->staff_id}/disable", ['reason' => 'Leaving the firm'])
        ->assertStatus(409)->assertJsonPath('code', 'last_role_manager');

    expect($manager->fresh()->is_active)->toBeTrue();
});

it('enables a member: the old password is gone and a new link is the only way back', function () {
    $member = Staff::factory()->role(SeedRole::FINANCE)->withPassword('member-passphrase-77')->withMfa()->create();
    slcPost($this, $this->token, SLC_STAFF."/{$member->staff_id}/disable", ['reason' => 'Suspected compromise'])->assertOk();

    $res = slcPost($this, $this->token, SLC_STAFF."/{$member->staff_id}/enable", ['reason' => 'Cleared after review'])
        ->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.password_set', false)
        ->assertJsonPath('data.mfa_enrolled', true);

    expect(StaffPassword::query()->whereKey($member->staff_id)->exists())->toBeFalse();
    $this->withoutToken()->postJson(SLC_LOGIN, ['email' => $member->email, 'password' => 'member-passphrase-77'])->assertStatus(401);

    [$origin, $token] = slcLink($member->email);
    expect($origin)->toBe('enabled');
    $this->withoutToken()->postJson('/api/v1/dashboard/auth/password/reset-confirm', [
        'token' => $token, 'password' => 'fresh-passphrase-4242', 'password_confirmation' => 'fresh-passphrase-4242',
    ])->assertOk();
    $this->withoutToken()->postJson(SLC_LOGIN, ['email' => $member->email, 'password' => 'fresh-passphrase-4242'])
        ->assertOk()->assertJsonPath('data.mfa_required', true);

    expect(AuditLog::query()->where('action', AuditEvent::STAFF_ENABLED->value)->sole()->reason)->toBe('Cleared after review');
    expect($res->json('data.id'))->toBe($member->staff_id);
});

it('refuses to enable an active member or yourself', function () {
    $live = Staff::factory()->role(SeedRole::OPERATIONS)->create();

    slcPost($this, $this->token, SLC_STAFF."/{$live->staff_id}/enable", ['reason' => 'Already active'])
        ->assertStatus(409)->assertJsonPath('code', 'staff_not_disabled');
    slcPost($this, $this->token, SLC_STAFF."/{$this->ceo->staff_id}/enable", ['reason' => 'Myself please'])
        ->assertForbidden()->assertJsonPath('code', 'escalation_denied');
});

it('shows an invited member with password_set false in the list', function () {
    slcPost($this, $this->token, SLC_STAFF, slcNew())->assertCreated();

    $rows = collect($this->bearer($this->token)->getJson(SLC_STAFF)->assertOk()->json('data'))->keyBy('email');

    expect($rows['amr@dahab.test']['password_set'])->toBeFalse()
        ->and($rows[$this->ceo->email]['password_set'])->toBeTrue();
});

it('replays an idempotent create and demands the key', function () {
    $key = (string) Str::uuid();

    $first = slcPost($this, $this->token, SLC_STAFF, slcNew(), $key)->assertCreated();
    $second = slcPost($this, $this->token, SLC_STAFF, slcNew(), $key)->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Staff::query()->where('email', 'amr@dahab.test')->count())->toBe(1);

    app('auth')->forgetGuards();
    $this->withToken($this->token)->withoutHeader('Idempotency-Key')->postJson(SLC_STAFF, slcNew(['email' => 'second@dahab.test']))
        ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
});

it('puts staff.manage on the three routes and no other', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('POST', $r->methods(), true)
        && preg_match('#^api/v1/dashboard/staff(/\{staff\}/(disable|enable))?$#', $r->uri()) === 1);

    expect($routes)->toHaveCount(3);
    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('staff.permission:staff.manage')->toContain('idempotent');
    }

    // And no other route asks for it.
    $others = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('staff.permission:staff.manage', $r->gatherMiddleware(), true));
    expect($others)->toHaveCount(3);
});

it('lets exactly one of two simultaneous disables win', function () {
    $member = Staff::factory()->role(SeedRole::OPERATIONS)->create();

    $statuses = [
        slcPost($this, $this->token, SLC_STAFF."/{$member->staff_id}/disable", ['reason' => 'First request'])->status(),
        slcPost($this, $this->token, SLC_STAFF."/{$member->staff_id}/disable", ['reason' => 'Second request'])->status(),
    ];

    expect($statuses)->toBe([200, 409])
        ->and(AuditLog::query()->where('action', AuditEvent::STAFF_DISABLED->value)->count())->toBe(1);
});

it('never lets a bad body set founder status', function () {
    $res = slcPost($this, $this->token, SLC_STAFF, slcNew(['is_founder' => true, 'is_system' => true]))->assertCreated();

    $member = Staff::query()->findOrFail($res->json('data.id'));
    expect($member->is_founder)->toBeFalse()->and($member->is_system)->toBeFalse();
});
