<?php

namespace Database\Factories;

use App\Enums\SeedRole;
use App\Models\FounderDeviceApproval;
use App\Models\Staff;
use App\Support\Auth\DeviceFingerprint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Held founder sign-ins (spec 021). Default: pending, for a fresh founder, 15 minutes left.
 * The poll ref is not kept; a test that polls creates the row through the sign-in instead.
 */
class FounderDeviceApprovalFactory extends Factory
{
    protected $model = FounderDeviceApproval::class;

    public function definition(): array
    {
        return [
            'staff_id' => fn () => Staff::factory()->role(SeedRole::COO)->founder()->create()->staff_id,
            'device_fingerprint' => DeviceFingerprint::hash('device-'.Str::random(12)),
            'requested_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'ref_hash' => hash('sha256', Str::random(40)),
            'platform' => 'web',
            'user_agent' => 'Mozilla/5.0 (test)',
            'ip_address' => '127.0.0.1',
        ];
    }

    public function pending(): static
    {
        return $this;
    }

    public function approved(?Staff $by = null): static
    {
        return $this->state(fn () => [
            'approved_by' => $by?->staff_id ?? Staff::factory()->role(SeedRole::CEO)->founder()->create()->staff_id,
            'approved_at' => now(),
        ]);
    }

    public function refused(?Staff $by = null): static
    {
        return $this->state(fn () => [
            'refused_by' => $by?->staff_id ?? Staff::factory()->role(SeedRole::CEO)->founder()->create()->staff_id,
            'refused_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'requested_at' => now()->subMinutes(20),
            'expires_at' => now()->subMinutes(5),
        ]);
    }

    public function consumed(?Staff $by = null): static
    {
        return $this->approved($by)->state(fn () => ['consumed_at' => now()]);
    }
}
