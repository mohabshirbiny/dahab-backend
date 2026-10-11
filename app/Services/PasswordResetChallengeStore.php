<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * The SMS code of a customer password reset (spec 020 research R1).
 *
 * Held in the cache, encrypted, and keyed by an HMAC of the phone number — so the key reveals
 * no number, and an unknown number needs no fake reference to keep the request uniform. The
 * payload carries only a hash of the code, the attempt counter and the resend accounting.
 * A challenge lives for an hour (the resend window); each code inside it expires after
 * `otp.ttl_seconds`. Modelled on CustomerLoginChallengeStore.
 */
final class PasswordResetChallengeStore
{
    private const TTL_SECONDS = 3600;

    /**
     * Starts a challenge for the phone, replacing any earlier one.
     *
     * @param  array<string, mixed>  $payload
     */
    public function begin(string $phone, array $payload): void
    {
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        $this->write($phone, [...$payload, 'challenge_expires_at' => $expiresAt->toIso8601String()], $expiresAt);
    }

    /** @return array<string, mixed>|null null when there is none, it expired or it was spent */
    public function find(string $phone): ?array
    {
        $raw = Cache::get($this->key($phone));

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
    public function save(string $phone, array $payload): void
    {
        $expiresAt = Carbon::parse($payload['challenge_expires_at']);

        if ($expiresAt->isPast()) {
            $this->forget($phone);

            return;
        }

        $this->write($phone, $payload, $expiresAt);
    }

    public function forget(string $phone): void
    {
        Cache::forget($this->key($phone));
    }

    /** Serialises request and confirm on one phone so a code is spent exactly once. */
    public function lock(string $phone): Lock
    {
        return Cache::lock($this->key($phone).':lock', 10);
    }

    /** @param array<string, mixed> $payload */
    private function write(string $phone, array $payload, Carbon $expiresAt): void
    {
        Cache::put(
            $this->key($phone),
            Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            $expiresAt,
        );
    }

    private function key(string $phone): string
    {
        return 'password-reset:'.hash_hmac('sha256', mb_strtolower(trim($phone)), (string) config('app.key'));
    }
}
