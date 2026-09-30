<?php

namespace App\Support\Listings;

use App\Enums\PieceCategory;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The public market list (spec 010 FR-020–FR-023, research R8). It runs in
 * the read-only `market` scope, which already limits the rows to live and
 * reserved listings; the state filter here repeats that on purpose.
 */
final class MarketQuery
{
    public const SORTS = ['newest', 'price_asc', 'price_desc'];

    public function __construct(private readonly ListingPricer $pricer) {}

    /**
     * @param  array{category?: string|null, karat?: int|null, piece_type?: int|null, branch?: int|null, min_g?: string|null, max_g?: string|null}  $filters
     * @return array{rows: Collection<int, Listing>, next_cursor: string|null}
     */
    public function page(array $filters, string $sort, ?ListingCursor $cursor, int $perPage): array
    {
        $query = $this->filtered($filters)->with(['pieceType', 'media', 'branches']);

        return match ($sort) {
            'price_asc' => ListingPage::byValue($query, $this->priceExpression(), true, $cursor, $perPage),
            'price_desc' => ListingPage::byValue($query, $this->priceExpression(), false, $cursor, $perPage),
            default => ListingPage::byTime($query, 'listed_at', true, $cursor, $perPage),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Listing>
     */
    private function filtered(array $filters): Builder
    {
        $query = Listing::query()->publiclyVisible();

        if (filled($filters['category'] ?? null)) {
            $query->where('listing.category', $filters['category']);
        }
        if (($filters['karat'] ?? null) !== null) {
            $query->where('listing.karat_code', (int) $filters['karat']);
        }
        if (($filters['piece_type'] ?? null) !== null) {
            $query->where('listing.piece_type_id', (int) $filters['piece_type']);
        }
        if (($filters['branch'] ?? null) !== null) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('listing_branch_option')
                ->whereColumn('listing_branch_option.listing_id', 'listing.listing_id')
                ->where('listing_branch_option.branch_id', (int) $filters['branch']));
        }
        if (filled($filters['min_g'] ?? null)) {
            $query->where('listing.stated_weight_g', '>=', $filters['min_g']);
        }
        if (filled($filters['max_g'] ?? null)) {
            $query->where('listing.stated_weight_g', '<=', $filters['max_g']);
        }

        return $query;
    }

    /**
     * What a buyer would pay, as SQL, for ordering only: gold is weight ×
     * (buyers-pay for its karat + making charge) at the current gold price,
     * stones are the asking price; a gold piece whose karat cannot be quoted
     * is NULL and sorts last. The literals are bcmath results of the price
     * calculator, checked to be plain decimals before they are inlined.
     */
    private function priceExpression(): string
    {
        $cases = '';

        foreach ($this->pricer->buyersPayByKarat() as $code => $rate) {
            if (preg_match('/^\d+(\.\d+)?$/', $rate) === 1) {
                $cases .= sprintf(' WHEN %d THEN ROUND(listing.stated_weight_g * (%s + listing.making_charge_per_g), 4)', (int) $code, $rate);
            }
        }

        $gold = $cases === '' ? 'NULL::numeric' : "CASE listing.karat_code{$cases} ELSE NULL END";

        return sprintf("CASE WHEN listing.category = '%s' THEN %s ELSE listing.asking_price END", PieceCategory::GOLD->value, $gold);
    }
}
