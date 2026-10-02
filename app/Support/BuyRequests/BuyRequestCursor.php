<?php

namespace App\Support\BuyRequests;

use Illuminate\Validation\ValidationException;

/**
 * Keyset position in a buyer's request list (newest first): the
 * `requested_at` of the last row, exact to the microsecond as the database
 * wrote it, and its id as the tie-breaker. Opaque; it only positions the page.
 */
final readonly class BuyRequestCursor
{
    public function __construct(public string $at, public string $id) {}

    public function encode(): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['t' => $this->at, 'i' => $this->id])), '+/', '-_'), '=');
    }

    public static function decode(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($value, '-_', '+/'), true), true);

        $ok = is_array($data)
            && is_string($data['t'] ?? null) && preg_match('/^[0-9T:.\-Z]{20,32}$/', $data['t']) === 1
            && is_string($data['i'] ?? null)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $data['i']) === 1;

        if (! $ok) {
            throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }

        return new self($data['t'], $data['i']);
    }
}
