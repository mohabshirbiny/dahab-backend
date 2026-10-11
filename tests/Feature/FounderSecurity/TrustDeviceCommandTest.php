<?php

use App\Enums\AuditEvent;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\FounderDeviceApproval;
use App\Models\Staff;
use App\Notifications\FounderSecurityNotification;
use App\Support\Auth\DeviceFingerprint;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

// Spec 021 C2 / research R10 (T029): `founder:trust-device` trusts a founder's device from the server.

beforeEach(function () {
    Notification::fake();
    $this->founder = Staff::factory()->role(SeedRole::COO)->founder()->create(['email' => 'coo@dahab.test']);
    $this->other = Staff::factory()->role(SeedRole::CEO)->founder()->create();
});

it('trusts the device, ends its waiting request and audits as the system', function () {
    $hash = DeviceFingerprint::hash('coo-phone');
    $pending = FounderDeviceApproval::factory()->create(['staff_id' => $this->founder->staff_id, 'device_fingerprint' => $hash]);

    $this->artisan('founder:trust-device', ['email' => 'coo@dahab.test', 'device-id' => 'coo-phone', '--force' => true])
        ->expectsOutputToContain('Trusted device '.substr($hash, 0, 8))
        ->assertSuccessful();

    expect($this->founder->isKnownDevice($hash))->toBeTrue()
        ->and($pending->fresh()->status())->toBe('expired');

    $audit = AuditLog::forEvent(AuditEvent::FOUNDER_DEVICE_TRUSTED_BY_CONSOLE);
    expect($audit->actor_staff_id)->toBe(SystemActor::id())
        ->and($audit->entity_id)->toBe($this->founder->staff_id)
        ->and(json_encode($audit->after_json))->not->toContain('coo-phone');

    Notification::assertSentTo([$this->founder, $this->other], FounderSecurityNotification::class,
        fn (FounderSecurityNotification $n) => $n->facts['actor'] === 'System');
});

it('asks for confirmation unless forced', function () {
    $this->artisan('founder:trust-device', ['email' => 'coo@dahab.test', 'device-id' => 'coo-phone'])
        ->expectsConfirmation('Trust this web device for coo@dahab.test? Sign-ins from it will no longer be held.', 'no')
        ->assertFailed();

    expect($this->founder->isKnownDevice(DeviceFingerprint::hash('coo-phone')))->toBeFalse();
});

it('refuses a non-founder, a disabled founder and a frozen founder', function (string $who) {
    $email = match ($who) {
        'non-founder' => Staff::factory()->role(SeedRole::OPERATIONS)->create()->email,
        'disabled' => Staff::factory()->role(SeedRole::COO)->founder()->disabled()->create()->email,
        'frozen' => Staff::factory()->role(SeedRole::COO)->founder()->frozen()->create()->email,
    };

    $this->artisan('founder:trust-device', ['email' => $email, 'device-id' => 'x-phone', '--force' => true])->assertFailed();

    expect(AuditLog::query()->where('action', AuditEvent::FOUNDER_DEVICE_TRUSTED_BY_CONSOLE->value)->exists())->toBeFalse();
})->with(['non-founder', 'disabled', 'frozen']);
