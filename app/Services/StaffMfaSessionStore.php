<?php

namespace App\Services;

use App\Models\Staff;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Short-lived state between "password accepted" and "TOTP confirmed" for a
 * staff sign-in. The only thing the client holds is an opaque `session_ref`;
 * no token exists until MFA completes. Payloads (which may carry a pending
 * TOTP secret and plaintext recovery codes) are encrypted at rest in the cache.
 */
final class StaffMfaSessionStore
{
    public const KIND_CHALLENGE = 'challenge';

    public const KIND_ENROLL = 'enroll';

    /**
     * @param  array<string, mixed>  $extra  merged into the stored payload
     * @return array{ref: string, expires_at: Carbon}
     */
    public function begin(Staff $staff, string $kind, array $extra = []): array
    {
        $ref = (string) Str::uuid();
        $expiresAt = now()->addSeconds((int) config('dahab-auth.mfa.session_ttl_seconds'));

        Cache::put(
            $this->key($ref),
            Crypt::encryptString(json_encode(['staff_id' => $staff->staff_id, 'kind' => $kind, ...$extra], JSON_THROW_ON_ERROR)),
            $expiresAt,
        );

        return ['ref' => $ref, 'expires_at' => $expiresAt];
    }

    /** @return array<string, mixed>|null null when unknown, expired, consumed or of another kind */
    public function find(string $ref, string $kind): ?array
    {
        $raw = Cache::get($this->key($ref));

        if (! is_string($raw)) {
            return null;
        }

        $payload = json_decode(Crypt::decryptString($raw), true, flags: JSON_THROW_ON_ERROR);

        return ($payload['kind'] ?? null) === $kind ? $payload : null;
    }

    public function forget(string $ref): void
    {
        Cache::forget($this->key($ref));
    }

    /** Serialises concurrent use of one session_ref so it can be consumed exactly once. */
    public function lock(string $ref): Lock
    {
        return Cache::lock($this->key($ref).':lock', 10);
    }

    private function key(string $ref): string
    {
        return 'staff-mfa-session:'.$ref;
    }
}
