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
    public function issue(string $customerId, UploadPurpose $purpose, string $storageRef, ?string $mime = null): array
    {
        $token = Str::random(48);
        $ttl = (int) config('dahab-identity.upload_token_ttl_seconds');

        Cache::put($this->key($token), [
            'customer_id' => $customerId,
            'purpose' => $purpose->value,
            'storage_ref' => $storageRef,
            'mime' => $mime,
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

    /**
     * The storage ref and content type behind a token, under the same rules as
     * resolve() (spec 009: a receipt is streamed back with its type).
     *
     * @return array{storage_ref: string, mime: string|null}|null
     */
    public function resolveEntry(string $token, string $customerId, UploadPurpose $purpose): ?array
    {
        $ref = $this->resolve($token, $customerId, $purpose);

        return $ref === null ? null : ['storage_ref' => $ref, 'mime' => Cache::get($this->key($token))['mime'] ?? null];
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
