<?php

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A PostgreSQL `TEXT[]` as a PHP list of strings. The values this is used for are short
 * identifiers (placeholder names, text kinds), so elements are written quoted and read back
 * without the full array grammar.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
final class PostgresTextArray implements CastsAttributes
{
    /** @return list<string> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '' || $value === '{}') {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        $inner = substr((string) $value, 1, -1);

        return $inner === '' ? [] : array_map(fn (string $e) => trim($e, '"'), explode(',', $inner));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $items = array_map(
            fn ($e) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $e).'"',
            array_values((array) ($value ?? [])),
        );

        return '{'.implode(',', $items).'}';
    }
}
