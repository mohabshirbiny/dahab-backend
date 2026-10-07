<?php

namespace App\Support\Account;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Keyset position in an inbox (spec 017 FR-032): the time and id of the last
 * item of a page, newest first. Opaque to clients; row-level security decides
 * which items exist.
 */
final readonly class InboxCursor
{
    public function __construct(public CarbonImmutable $at, public string $id) {}

    public function encode(): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['t' => $this->at->format('Y-m-d\TH:i:s.uP'), 'i' => $this->id])), '+/', '-_'), '=');
    }

    public static function decode(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($value, '-_', '+/'), true), true);
        try {
            if (! is_array($data) || ! is_string($data['i'] ?? null) || preg_match('/^[0-9a-f-]{36}$/', $data['i']) !== 1) {
                throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
            }

            return new self(CarbonImmutable::parse((string) $data['t']), $data['i']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }
    }
}
