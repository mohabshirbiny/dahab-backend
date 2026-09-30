<?php

namespace App\Support\Listings;

use App\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Keyset pages over listings (spec 010 research R14). The sort value is read
 * back as an exact string computed by the database (`sort_key`), so a cursor
 * never loses the microseconds or the decimals that order two rows.
 */
final class ListingPage
{
    /**
     * A page ordered by a timestamp column, newest or oldest first, with the
     * listing id as the tie-breaker.
     *
     * @param  Builder<Listing>  $query
     * @return array{rows: Collection<int, Listing>, next_cursor: string|null}
     */
    public static function byTime(Builder $query, string $column, bool $newestFirst, ?ListingCursor $cursor, int $perPage): array
    {
        $key = "to_char(listing.{$column} AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')";
        $dir = $newestFirst ? 'desc' : 'asc';

        $query->select('listing.*')->selectRaw("{$key} AS sort_key")
            ->orderBy("listing.{$column}", $dir)->orderBy('listing.listing_id', $dir);

        if ($cursor !== null) {
            $query->whereRaw(
                "(listing.{$column}, listing.listing_id) ".($newestFirst ? '<' : '>').' (?::timestamptz, ?::uuid)',
                [$cursor->value, $cursor->id],
            );
        }

        return self::cut($query->limit($perPage + 1)->get(), $perPage);
    }

    /**
     * A page ordered by a computed numeric expression (the market price),
     * rows without a value last, ties by listing id ascending.
     *
     * @param  Builder<Listing>  $query
     * @param  string  $expression  SQL built from trusted literals only
     * @return array{rows: Collection<int, Listing>, next_cursor: string|null}
     */
    public static function byValue(Builder $query, string $expression, bool $ascending, ?ListingCursor $cursor, int $perPage): array
    {
        $query->select('listing.*')->selectRaw("({$expression})::text AS sort_key")
            ->orderByRaw("({$expression}) ".($ascending ? 'ASC' : 'DESC').' NULLS LAST')
            ->orderBy('listing.listing_id');

        if ($cursor !== null && $cursor->value !== null) {
            $query->whereRaw(
                "(({$expression}) ".($ascending ? '>' : '<')." ?::numeric OR (({$expression}) = ?::numeric AND listing.listing_id > ?::uuid) OR ({$expression}) IS NULL)",
                [$cursor->value, $cursor->value, $cursor->id],
            );
        } elseif ($cursor !== null) {
            $query->whereRaw("({$expression}) IS NULL AND listing.listing_id > ?::uuid", [$cursor->id]);
        }

        return self::cut($query->limit($perPage + 1)->get(), $perPage);
    }

    /**
     * @param  Collection<int, Listing>  $rows
     * @return array{rows: Collection<int, Listing>, next_cursor: string|null}
     */
    private static function cut(Collection $rows, int $perPage): array
    {
        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->values();
        $last = $rows->last();

        return [
            'rows' => $rows,
            'next_cursor' => $more && $last !== null
                ? (new ListingCursor($last->getAttribute('sort_key'), $last->listing_id))->encode()
                : null,
        ];
    }
}
