<?php

namespace App\Actions\Invoices;

use App\Models\TaxInvoice;
use App\Support\Invoices\InvoiceFigures;
use App\Support\Invoices\InvoiceListQuery;
use App\Support\Listings\ListingCursor;
use App\Support\Withdrawals\KeysetPage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The staff Invoices list (spec 016 FR-010, FR-011): newest first, keyset
 * pages, each row with what was credited and its derived status, and the
 * month's and the period's figures.
 */
final class ListInvoicesAction
{
    public function __construct(private readonly InvoiceFigures $figures) {}

    /** @return array{rows: Collection<int, TaxInvoice>, next_cursor: string|null, figures: array<string, mixed>} */
    public function handle(InvoiceListQuery $filters, ?ListingCursor $cursor, int $perPage): array
    {
        $page = KeysetPage::byTime($filters->apply(TaxInvoice::query()->with('customer')), 'tax_invoice', 'issued_at', 'invoice_id', true, $cursor, $perPage);
        self::withCredited($page['rows']);

        return $page + ['figures' => ['month' => $this->figures->month(), 'period' => $this->figures->period($filters->from, $filters->to)]];
    }

    /**
     * Set `credited_sum` on each invoice with one grouped query.
     *
     * @param  Collection<int, TaxInvoice>  $invoices
     */
    public static function withCredited(Collection $invoices): void
    {
        if ($invoices->isEmpty()) {
            return;
        }

        $sums = DB::table('credit_note')->whereIn('invoice_id', $invoices->pluck('invoice_id'))->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(gross_amount)::numeric(18,4)::text AS s')->pluck('s', 'invoice_id');
        $invoices->each(fn (TaxInvoice $i) => $i->setAttribute('credited_sum', (string) ($sums[$i->invoice_id] ?? '0')));
    }
}
