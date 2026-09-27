<?php

namespace App\Support\Audit;

use Illuminate\Validation\ValidationException;

/**
 * Keyset position (created_at, audit_id) of the last row of a page (research
 * R4). Opaque to clients; rows written between pages cannot shift a page.
 */
final readonly class AuditCursor
{
    public function __construct(
        public string $createdAt,
        public int $auditId,
    ) {}

    public function encode(): string
    {
        return rtrim(strtr(base64_encode(json_encode([$this->createdAt, $this->auditId])), '+/', '-_'), '=');
    }

    public static function decode(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($value, '-_', '+/'), true), true);

        if (! is_array($data) || count($data) !== 2 || ! is_string($data[0]) || ! is_int($data[1])
            || strtotime($data[0]) === false) {
            throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }

        return new self($data[0], $data[1]);
    }
}
