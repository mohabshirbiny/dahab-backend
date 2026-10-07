<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A phone-number change waiting for the code sent to the new number (spec 017
 * FR-001, research R2). Part 1 §2: an OTP is not stored — it lives in the
 * cache, encrypted, as a hash, for `otp.ttl_seconds`. One live challenge per
 * customer: a new request forgets the previous one.
 */
final class ContactChangeChallengeStore
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, expires_at: Carbon}
     */
    public function begin(string $customerId, array $payload): array
    {
        $previous = Cache::get($this->pointer($customerId));
        if (is_string($previous)) {
            Cache::forget($this->key($previous));
        }

        $id = (string) Str::uuid();
        $expiresAt = now()->addSeconds((int) config('dahab-auth.otp.ttl_seconds'));

        $this->write($id, [...$payload, 'customer_id' => $customerId, 'expires_at' => $expiresAt->toIso8601String()], $expiresAt);
        Cache::put($this->pointer($customerId), $id, $expiresAt);

        return ['id' => $id, 'expires_at' => $expiresAt];
    }

    /** @return array<string, mixed>|null null when unknown, expired, replaced or spent */
    public function find(string $id): ?array
    {
        $raw = Cache::get($this->key($id));

        return is_string($raw) ? json_decode(Crypt::decryptString($raw), true, flags: JSON_THROW_ON_ERROR) : null;
    }

    /** @param  array<string, mixed>  $payload */
    public function save(string $id, array $payload): void
    {
        $expiresAt = Carbon::parse($payload['expires_at']);
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

    /** Forget whatever change the customer has pending (closing the account). */
    public function forgetFor(string $customerId): void
    {
        $id = Cache::pull($this->pointer($customerId));
        if (is_string($id)) {
            $this->forget($id);
        }
    }

    /** One request or confirmation at a time per customer, so a code is spent exactly once. */
    public function lock(string $customerId): Lock
    {
        return Cache::lock('contact-change-lock:'.$customerId, 10);
    }

    /** @param  array<string, mixed>  $payload */
    private function write(string $id, array $payload, Carbon $expiresAt): void
    {
        Cache::put($this->key($id), Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), $expiresAt);
    }

    private function key(string $id): string
    {
        return 'contact-change:'.$id;
    }

    private function pointer(string $customerId): string
    {
        return 'contact-change-customer:'.$customerId;
    }
}
