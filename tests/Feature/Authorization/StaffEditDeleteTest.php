<?php

use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Notifications\StaffPasswordLinkNotification;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// docs/features/staff-edit-delete.md: change a member's details, delete a member (soft, final),
// and the phone is required for a new member.

const SED_STAFF = '/api/v1/dashboard/staff';
const SED_LOGIN = '/api/v1/dashboard/auth/login';

beforeEach(function () {
    Notification::fake();
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->withPassword('ceo-passphrase-123')->create(['full_name' => 'Founder CEO']);
    $this->token = staffAccessToken($this->ceo);
    $this->ops = Staff::factory()->role(SeedRole::OPERATIONS)->withPassword('ops-passphrase-123')
        ->create(['full_name' => 'Amr Salah', 'email' => 'amr@dahab.test', 'phone' => '+201000000009']);
});

function sedSend($test, string $method, string $path, array $body = [], ?string $token = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($token ?? $test->token)->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->json($method, $path, $body);
}

it('changes only the fields sent, audits before and after, and needs a reason', function () {
    sedSend($this, 'PATCH', SED_STAFF."/{$this->ops->staff_id}", ['phone' => '+201000000077'])
        ->assertStatus(422)->assertJsonPath('code', 'reason_required');

    sedSend($this, 'PATCH', SED_STAFF."/{$this->ops->staff_id}", ['full_name' => 'Amr S. Salah', 'phone' => '+201000000077', 'reason' => 'New number'])
        ->assertOk()
        ->assertJsonPath('data.full_name', 'Amr S. Salah')
        ->assertJsonPath('data.phone', '+201000000077')
        ->assertJsonPath('data.email', 'amr@dahab.test');

    $row = AuditLog::query()->where('action', AuditEvent::STAFF_UPDATED->value)->sole();
    expect($row->entity_id)->toBe($this->ops->staff_id);
});

it('refuses an empty phone, a bad phone and an email another member uses', function () {
    Staff::factory()->role(SeedRole::FINANCE)->create(['email' => 'taken@dahab.test']);

    sedSend($this, 'PATCH', SED_STAFF."/{$this->ops->staff_id}", ['phone' => '', 'reason' => 'Clear it'])->assertStatus(422);
    sedSend($this, 'PATCH', SED_STAFF."/{$this->ops->staff_id}", ['phone' => '0100', 'reason' => 'Bad one'])->assertStatus(422);
    sedSend($this, 'PATCH', SED_STAFF."/{$this->ops->staff_id}", ['email' => 'TAKEN@dahab.test', 'reason' => 'Clash'])->assertStatus(422);
    sedSend($this, 'PATCH', SED_STAFF."/{$this->ops->staff_id}", ['email' => 'amr@dahab.test', 'reason' => 'Same as mine'])->assertOk();
});

it('sends a fresh invitation to the new email of a member with no password yet', function () {
    $invited = Staff::factory()->role(SeedRole::FINANCE)->create(['email' => 'old@dahab.test', 'phone' => '+201000000055']);

    sedSend($this, 'PATCH', SED_STAFF."/{$invited->staff_id}", ['email' => 'New@dahab.test', 'reason' => 'Typo in the email'])
        ->assertOk()->assertJsonPath('data.email', 'new@dahab.test');

    Notification::assertSentOnDemand(StaffPasswordLinkNotification::class,
        fn ($n, $channels, AnonymousNotifiable $to) => ($to->routes['mail'] ?? null) === 'new@dahab.test' && $n->origin === 'invitation');
});

it("lets only a founder change a founder's details", function () {
    $coo = Staff::factory()->role(SeedRole::COO)->founder()->create(['phone' => '+201000000033']);
    $admin = Staff::factory()->role(SeedRole::FINANCE)->create();
    $admin->givePermissionTo('staff.manage');

    sedSend($this, 'PATCH', SED_STAFF."/{$coo->staff_id}", ['phone' => '+201000000044', 'reason' => 'New number'], staffAccessToken($admin))
        ->assertForbidden()->assertJsonPath('code', 'founder_protected');

    sedSend($this, 'PATCH', SED_STAFF."/{$coo->staff_id}", ['phone' => '+201000000044', 'reason' => 'New number'])
        ->assertOk()->assertJsonPath('data.phone', '+201000000044');
});

it('deletes a member: gone from the list, signed out, cannot sign in, email free again', function () {
    $opsToken = staffAccessToken($this->ops);

    sedSend($this, 'DELETE', SED_STAFF."/{$this->ops->staff_id}")->assertStatus(422)->assertJsonPath('code', 'reason_required');
    sedSend($this, 'DELETE', SED_STAFF."/{$this->ops->staff_id}", ['reason' => 'Left the company'])->assertNoContent();

    $this->ops->refresh();
    expect($this->ops->deleted_at)->not->toBeNull()->and($this->ops->is_active)->toBeFalse()
        ->and($this->ops->tokens()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AuditEvent::STAFF_DELETED->value)->count())->toBe(1);

    app('auth')->forgetGuards();
    $ids = collect($this->withToken($this->token)->getJson(SED_STAFF)->assertOk()->json('data'))->pluck('id');
    expect($ids)->not->toContain($this->ops->staff_id);
    $this->withToken($this->token)->getJson(SED_STAFF."/{$this->ops->staff_id}")->assertNotFound();
    app('auth')->forgetGuards();
    $this->withToken($opsToken)->getJson('/api/v1/dashboard/auth/me')->assertUnauthorized();

    app('auth')->forgetGuards();
    $this->postJson(SED_LOGIN, ['email' => 'amr@dahab.test', 'password' => 'ops-passphrase-123'])->assertUnauthorized();

    sedSend($this, 'POST', SED_STAFF, [
        'full_name' => 'Amr Again', 'email' => 'amr@dahab.test', 'phone' => '+201000000010', 'roles' => ['operations'], 'reason' => 'Hired back',
    ])->assertCreated();
    sedSend($this, 'DELETE', SED_STAFF."/{$this->ops->staff_id}", ['reason' => 'Twice'])->assertNotFound();
});

it('never deletes yourself or a founder', function () {
    $coo = Staff::factory()->role(SeedRole::COO)->founder()->create();

    sedSend($this, 'DELETE', SED_STAFF."/{$this->ceo->staff_id}", ['reason' => 'Myself'])->assertForbidden();
    sedSend($this, 'DELETE', SED_STAFF."/{$coo->staff_id}", ['reason' => 'A founder'])
        ->assertForbidden()->assertJsonPath('code', 'founder_protected');
});

it('requires a phone for a new member', function () {
    sedSend($this, 'POST', SED_STAFF, [
        'full_name' => 'No Phone', 'email' => 'nophone@dahab.test', 'roles' => ['operations'], 'reason' => 'New hire, Cairo branch',
    ])->assertStatus(422)->assertJsonValidationErrors('phone');
});
