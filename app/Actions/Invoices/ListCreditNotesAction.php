<?php

namespace App\Actions\Invoices;

use App\Models\CreditNote;
use App\Support\Listings\ListingCursor;
use App\Support\Withdrawals\KeysetPage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** The staff Credit notes list (spec 016 FR-014): newest first, a Cairo period, a search. */
final class ListCreditNotesAction
{
    public const RELATIONS = ['invoice', 'customer', 'issuedBy'];

    /** @return array{rows: Collection<int, CreditNote>, next_cursor: string|null} */
    public function handle(string $from, string $to, ?string $q, ?ListingCursor $cursor, int $perPage): array
    {
        $query = CreditNote::query()->with(self::RELATIONS)
            ->where('credit_note.issued_at', '>=', CarbonImmutable::parse($from, 'Africa/Cairo')->startOfDay())
            ->where('credit_note.issued_at', '<', CarbonImmutable::parse($to, 'Africa/Cairo')->addDay()->startOfDay());

        if (filled($q)) {
            $term = trim((string) $q);
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn (Builder $w) => $w->where('credit_note.credit_note_no', 'ilike', $like)
                ->orWhere('credit_note.reason', 'ilike', $like)
                ->orWhereHas('invoice', fn (Builder $i) => $i->where('invoice_no', 'ilike', $like))
                ->orWhereHas('customer', fn (Builder $c) => $c->where('display_ref', $term)->orWhere('full_name', 'ilike', $like)));
        }

        return KeysetPage::byTime($query, 'credit_note', 'issued_at', 'credit_note_id', true, $cursor, $perPage);
    }
}
