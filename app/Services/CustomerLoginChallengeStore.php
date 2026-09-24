<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A customer sign-in held pending an SMS code because it came from a device
 * the customer has not used before (Part 1 §2.3).
 *
 * Lives only in the cache under an opaque `challenge_id` — nothing is written
 * to the database until the code is verified, at which point the device is
 * trusted and a session issued. Mirrors CustomerRegistrationSessionStore: the
 * payload carries an OTP hash, so it is encrypted on top of the cache driver.
 *
 * A challenge lives for an hour (the resend window); each code inside it
 * expires after `otp.ttl_seconds`.
 */
final class CustomerLoginChallengeStore
{
    private const TTL_SECONDS = 3600;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, expires_at: Carbon}
     */
    public function begin(array $payload): array
    {
        $id = (string) Str::uuid();
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        $this->write($id, [...$payload, 'challenge_expires_at' => $expiresAt->toIso8601String()], $expiresAt);

        return ['id' => $id, 'expires_at' => $expiresAt];
    }

    /** @return array<string, mixed>|null null when the id is unknown, expired or consumed */
    public function find(string $id): ?array
    {
        $raw = Cache::get($this->key($id));

        if (! is_string($raw)) {
            return null;
        }

        return json_decode(Crypt::decryptString($raw), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Overwrites the payload without extending the challenge's own deadline.
     *
     * @param  array<string, mixed>  $payload
     */
    public function save(string $id, array $payload): void
    {
        $expiresAt = Carbon::parse($payload['challenge_expires_at']);

        if ($expiresAt->isPast()) {
            $this->forget($id);

            return;
        }

        $this->write($id, $payload, $expiresAt);
    }

    public function forget(string $id): void
    {
        Cache::forget($this->key($id));
    }

    /** Serialises verify/resend on one challenge so a code is consumed exactly once. */
    public function lock(string $id): Lock
    {
        return Cache::lock($this->key($id).':lock', 10);
    }

    /** @param array<string, mixed> $payload */
    private function write(string $id, array $payload, Carbon $expiresAt): void
    {
        Cache::put(
            $this->key($id),
            Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            $expiresAt,
        );
    }

    private function key(string $id): string
    {
        return 'customer-login-challenge:'.$id;
    }
}
