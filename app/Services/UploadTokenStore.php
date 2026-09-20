<?php

namespace App\Services;

use App\Enums\UploadPurpose;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Maps a customer's opaque `upload_token` to the private object it refers to.
 * The token is bound to one customer and one purpose; only its hash is used as
 * the cache key, so the cache never holds a usable credential.
 */
final class UploadTokenStore
{
    /** @return array{token: string, expires_in: int} */
    public function issue(string $customerId, UploadPurpose $purpose, string $storageRef): array
    {
        $token = Str::random(48);
        $ttl = (int) config('dahab-identity.upload_token_ttl_seconds');

        Cache::put($this->key($token), [
            'customer_id' => $customerId,
            'purpose' => $purpose->value,
            'storage_ref' => $storageRef,
        ], now()->addSeconds($ttl));

        return ['token' => $token, 'expires_in' => $ttl];
    }

    /** The storage ref behind a token, or null unless it is live, this customer's, and for this purpose. */
    public function resolve(string $token, string $customerId, UploadPurpose $purpose): ?string
    {
        $entry = Cache::get($this->key($token));

        if (! is_array($entry) || $entry['customer_id'] !== $customerId || $entry['purpose'] !== $purpose->value) {
            return null;
        }

        return $entry['storage_ref'];
    }

    public function forget(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'customer-upload:'.hash('sha256', $token);
    }
}
