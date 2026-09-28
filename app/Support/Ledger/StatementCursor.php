<?php

namespace App\Support\Ledger;

use Illuminate\Validation\ValidationException;

/**
 * Keyset position in a Wallet statement (oldest first): the sequence key of
 * the last entry (grain `each`) or the last period (`day` / `month`). It only
 * positions the page — every balance is recomputed from the ledger — so a
 * crafted cursor can skip rows but never change a figure.
 */
final readonly class StatementCursor
{
    public function __construct(
        public ?int $seq = null,
        public ?string $period = null,
    ) {}

    public function encode(): string
    {
        return rtrim(strtr(base64_encode(json_encode(['s' => $this->seq, 'p' => $this->period])), '+/', '-_'), '=');
    }

    public static function decode(?string $value, string $grain): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($value, '-_', '+/'), true), true);
        $valid = is_array($data) && match ($grain) {
            'each' => is_int($data['s'] ?? null) && $data['s'] > 0,
            'day' => is_string($data['p'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['p']) === 1,
            'month' => is_string($data['p'] ?? null) && preg_match('/^\d{4}-\d{2}$/', $data['p']) === 1,
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }

        return new self($data['s'] ?? null, $data['p'] ?? null);
    }
}
