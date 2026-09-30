<?php

namespace App\Support\Listings;

use Illuminate\Validation\ValidationException;

/**
 * Keyset position in a listing list (spec 010 research R14): the sort value
 * of the last row of a page — a timestamp, or a price for the market's price
 * sorts (null when that row had no price) — and its id as the tie-breaker.
 * Opaque to clients. It only positions the page: row-level security and the
 * filters decide which rows exist, and every price is recomputed.
 */
final readonly class ListingCursor
{
    public function __construct(public ?string $value, public string $id) {}

    public function encode(): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['v' => $this->value, 'i' => $this->id])), '+/', '-_'), '=');
    }

    public static function decode(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($value, '-_', '+/'), true), true);

        $ok = is_array($data)
            && array_key_exists('v', $data)
            && ($data['v'] === null || (is_string($data['v']) && $data['v'] !== '' && strlen($data['v']) <= 40))
            && is_string($data['i'] ?? null)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $data['i']) === 1
            && ($data['v'] === null || preg_match('/^[0-9T:.+\- Z]+$/', $data['v']) === 1);

        if (! $ok) {
            throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }

        return new self($data['v'], $data['i']);
    }
}
