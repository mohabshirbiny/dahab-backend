<?php

namespace App\Support\Withdrawals;

use App\Support\Listings\ListingCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Keyset pages over a timestamp column with the row id as the tie-breaker
 * (the spec 010 pattern, api-contract "Keyset pages"). The cursor is the
 * generic `{value, id}` cursor of `ListingCursor`; the sort value is read back
 * exactly as the database orders it.
 */
final class KeysetPage
{
    /**
     * @param  Builder<Model>  $query
     * @return array{rows: Collection<int, Model>, next_cursor: string|null}
     */
    public static function byTime(Builder $query, string $table, string $column, string $idColumn, bool $newestFirst, ?ListingCursor $cursor, int $perPage): array
    {
        $key = "to_char({$table}.{$column} AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')";
        $dir = $newestFirst ? 'desc' : 'asc';

        $query->select("{$table}.*")->selectRaw("{$key} AS sort_key")
            ->orderBy("{$table}.{$column}", $dir)->orderBy("{$table}.{$idColumn}", $dir);

        if ($cursor !== null) {
            $query->whereRaw(
                "({$table}.{$column}, {$table}.{$idColumn}) ".($newestFirst ? '<' : '>').' (?::timestamptz, ?::uuid)',
                [$cursor->value, $cursor->id],
            );
        }

        $rows = $query->limit($perPage + 1)->get();
        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->values();
        $last = $rows->last();

        return [
            'rows' => $rows,
            'next_cursor' => $more && $last !== null
                ? (new ListingCursor($last->getAttribute('sort_key'), (string) $last->getAttribute($idColumn)))->encode()
                : null,
        ];
    }
}
