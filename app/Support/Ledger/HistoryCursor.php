<?php

namespace App\Support\Ledger;

use Illuminate\Validation\ValidationException;

/**
 * Keyset position in a customer's wallet history: the sequence key
 * (`MIN(posting_id)` of an entry) of the last row of a page (spec 008
 * research R7). Opaque to clients. It only positions the page: every figure
 * is recomputed server-side, and row-level security limits the rows to the
 * customer's own, so it carries nothing worth tampering with.
 */
final readonly class HistoryCursor
{
    public function __construct(public int $seq) {}

    public function encode(): string
    {
        return rtrim(strtr(base64_encode(json_encode(['s' => $this->seq])), '+/', '-_'), '=');
    }

    public static function decode(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($value, '-_', '+/'), true), true);

        if (! is_array($data) || ! is_int($data['s'] ?? null) || $data['s'] < 1) {
            throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }

        return new self($data['s']);
    }
}
