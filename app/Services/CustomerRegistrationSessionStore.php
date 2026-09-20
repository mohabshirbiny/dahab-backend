<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Short-lived state between "registration started" and "customer created".
 *
 * This is the whole reason no registration draft table exists: an in-flight
 * registration lives in the cache under an opaque `registration_ref` and
 * nothing else. Abandon the flow and the entry expires — no customer row, no
 * draft row, nothing to reconcile. It mirrors StaffMfaSessionStore, which
 * holds the equivalent state for a staff sign-in awaiting TOTP.
 *
 * The payload carries a password hash and an OTP hash, so it is encrypted at
 * rest in the cache on top of whatever the cache driver provides.
 */
final class CustomerRegistrationSessionStore
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{ref: string, expires_at: Carbon}
     */
    public function begin(array $payload): array
    {
        $ref = (string) Str::uuid();
        $expiresAt = now()->addSeconds($this->ttlSeconds());

        $this->write($ref, [...$payload, 'expires_at' => $expiresAt->toIso8601String()], $expiresAt);

        return ['ref' => $ref, 'expires_at' => $expiresAt];
    }

    /** @return array<string, mixed>|null null when the ref is unknown, expired or already consumed */
    public function find(string $ref): ?array
    {
        $raw = Cache::get($this->key($ref));

        if (! is_string($raw)) {
            return null;
        }

        return json_decode(Crypt::decryptString($raw), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Overwrites the payload without extending the original deadline — a
     * registration cannot be kept alive indefinitely by retrying OTPs.
     *
     * @param  array<string, mixed>  $payload
     */
    public function save(string $ref, array $payload): void
    {
        $expiresAt = isset($payload['expires_at'])
            ? Carbon::parse($payload['expires_at'])
            : now()->addSeconds($this->ttlSeconds());

        if ($expiresAt->isPast()) {
            $this->forget($ref);

            return;
        }

        $this->write($ref, $payload, $expiresAt);
    }

    public function forget(string $ref): void
    {
        Cache::forget($this->key($ref));
    }

    /**
     * Serialises concurrent use of one ref so it is consumed exactly once.
     * This is what stops a double-submitted final step from creating two
     * customers, and therefore two sets of registration notifications.
     */
    public function lock(string $ref): Lock
    {
        return Cache::lock($this->key($ref).':lock', 10);
    }

    /** @param array<string, mixed> $payload */
    private function write(string $ref, array $payload, Carbon $expiresAt): void
    {
        Cache::put(
            $this->key($ref),
            Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            $expiresAt,
        );
    }

    private function ttlSeconds(): int
    {
        return (int) config('dahab-auth.registration.session_ttl_seconds');
    }

    private function key(string $ref): string
    {
        return 'customer-registration:'.$ref;
    }
}
