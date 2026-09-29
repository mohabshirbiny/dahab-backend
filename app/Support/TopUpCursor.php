<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Keyset position in a top-up list (spec 009): the `topup_no` of the last row
 * of a page. `topup_no` rises with submission time, so it is both the order
 * (newest first) and the cursor. Opaque to clients; it only positions the
 * page, and row-level security / permissions decide which rows exist.
 */
final readonly class TopUpCursor
{
    public function __construct(public int $no) {}

    public function encode(): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['n' => $this->no])), '+/', '-_'), '=');
    }

    public static function decode(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($value, '-_', '+/'), true), true);

        if (! is_array($data) || ! is_int($data['n'] ?? null) || $data['n'] < 1) {
            throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }

        return new self($data['n']);
    }
}
