<?php

use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Models\AccountFreeze;
use App\Models\AuditLog;
use App\Models\FounderDeviceApproval;
use App\Models\Staff;
use App\Notifications\FounderSecurityNotification;
use App\Support\SystemActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Spec 021 Story 2 (T043): a founder freezes the other founder.

const FRZ_URL = '/api/v1/dashboard/founder/freeze';
const FRZ_PASSWORD = 'founder-passphrase-1';

beforeEach(function () {
    Notification::fake();
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->create(['full_name' => 'Founder CEO']);
    $this->coo = Staff::factory()->role(SeedRole::COO)->founder()->withPassword(FRZ_PASSWORD)->create(['full_name' => 'Founder COO']);
});

function frzPost($test, Staff $actor, array $body, ?string $token = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($token ?? staffAccessToken($actor))
        ->postJson(FRZ_URL, $body, ['Idempotency-Key' => (string) Str::uuid()]);
}

it('freezes the other founder: tokens gone, every request refused, approvals ended, audited and told', function () {
    $cooToken = staffAccessToken($this->coo);
    staffAccessToken($this->coo);
    $pending = FounderDeviceApproval::factory()->create(['staff_id' => $this->coo->staff_id]);

    $response = frzPost($this, $this->ceo, ['staff_id' => $this->coo->staff_id, 'reason' => 'Lost phone, possible compromise'])
        ->assertCreated()
        ->assertJsonPath('data.frozen_staff.id', $this->coo->staff_id)
        ->assertJsonPath('data.frozen_by.id', $this->ceo->staff_id)
        ->assertJsonPath('data.reason', 'Lost phone, possible compromise')
        ->assertJsonPath('data.stage', 'awaiting_first')
        ->assertJsonPath('data.confirmations', [])
        ->assertJsonPath('data.pending_approvals_ended', 1);

    expect($response->json('data.sessions_ended'))->toBeGreaterThanOrEqual(4)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $this->coo->staff_id)->count())->toBe(0)
        ->and($pending->fresh()->status())->toBe('expired');

    // The old token is gone; a token minted later is refused on every route.
    app('auth')->forgetGuards();
    $this->withToken($cooToken)->getJson('/api/v1/dashboard/auth/me')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken(staffAccessToken($this->coo))->getJson('/api/v1/dashboard/auth/me')
        ->assertForbidden()->assertJsonPath('code', 'account_frozen');
    expect(AuditLog::query()->where('action', AuditEvent::STAFF_PERMISSION_DENIED->value)
        ->where('actor_staff_id', $this->coo->staff_id)->exists())->toBeTrue();

    // Sign-in answers account_frozen after the password.
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/dashboard/auth/login', ['email' => $this->coo->email, 'password' => FRZ_PASSWORD])
        ->assertForbidden()->assertJsonPath('code', 'account_frozen');

    $audit = AuditLog::forEvent(AuditEvent::FOUNDER_FROZEN);
    expect($audit->actor_staff_id)->toBe($this->ceo->staff_id)
        ->and($audit->entity_id)->toBe($this->coo->staff_id)
        ->and($audit->reason)->toBe('Lost phone, possible compromise');

    Notification::assertSentTo([$this->ceo, $this->coo], FounderSecurityNotification::class,
        fn (FounderSecurityNotification $n, array $channels) => $n->facts['event'] === 'founder.frozen'
            && in_array('sms', $channels, true) && in_array('mail', $channels, true));
});

it('requires a reason', function (?string $reason) {
    frzPost($this, $this->ceo, ['staff_id' => $this->coo->staff_id, 'reason' => $reason])
        ->assertStatus(422)->assertJsonPath('code', 'reason_required');

    expect(AccountFreeze::query()->count())->toBe(0);
})->with([null, '   ', 'abc']);

it('refuses to freeze yourself', function () {
    frzPost($this, $this->ceo, ['staff_id' => $this->ceo->staff_id, 'reason' => 'Testing myself'])
        ->assertForbidden()->assertJsonPath('code', 'founder_self_action');
});

it('refuses a target that is not an active founder', function (string $who) {
    $target = match ($who) {
        'non-founder' => Staff::factory()->role(SeedRole::OPERATIONS)->create()->staff_id,
        'disabled founder' => Staff::factory()->role(SeedRole::COO)->founder()->disabled()->create()->staff_id,
        'unknown' => (string) Str::uuid(),
        'system' => SystemActor::id(),
    };

    frzPost($this, $this->ceo, ['staff_id' => $target, 'reason' => 'Not allowed here'])
        ->assertStatus(422)->assertJsonPath('code', 'freeze_target_invalid');
})->with(['non-founder', 'disabled founder', 'unknown', 'system']);

it('refuses a second freeze of the same founder', function () {
    AccountFreeze::factory()->create(['frozen_staff_id' => $this->coo->staff_id, 'frozen_by' => $this->ceo->staff_id]);
    $third = Staff::factory()->role(SeedRole::CEO)->founder()->create();

    frzPost($this, $third, ['staff_id' => $this->coo->staff_id, 'reason' => 'Again, to be sure'])
        ->assertStatus(409)->assertJsonPath('code', 'already_frozen');
});

it('leaves exactly one founder frozen when both try to freeze each other', function () {
    $cooToken = staffAccessToken($this->coo);

    frzPost($this, $this->ceo, ['staff_id' => $this->coo->staff_id, 'reason' => 'First to act'])->assertCreated();
    // The COO's token is gone; a fresh one is still refused (frozen actor).
    frzPost($this, $this->coo, ['staff_id' => $this->ceo->staff_id, 'reason' => 'Second to act'], staffAccessToken($this->coo))
        ->assertForbidden()->assertJsonPath('code', 'account_frozen');
    frzPost($this, $this->coo, ['staff_id' => $this->ceo->staff_id, 'reason' => 'Old token'], $cooToken)->assertUnauthorized();

    expect(AccountFreeze::query()->whereNull('unfrozen_at')->pluck('frozen_staff_id')->all())->toBe([$this->coo->staff_id]);
});

it('keeps freezing founder-only', function () {
    $operations = Staff::factory()->role(SeedRole::OPERATIONS)->create();

    frzPost($this, $operations, ['staff_id' => $this->coo->staff_id, 'reason' => 'Not a founder'])
        ->assertForbidden()->assertJsonPath('code', 'founder_only');
});

it('still lets a frozen account sign out', function () {
    AccountFreeze::factory()->create(['frozen_staff_id' => $this->coo->staff_id, 'frozen_by' => $this->ceo->staff_id]);

    app('auth')->forgetGuards();
    $this->withToken(staffAccessToken($this->coo))->postJson('/api/v1/dashboard/auth/logout')->assertNoContent();
});
