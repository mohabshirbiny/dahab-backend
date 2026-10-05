<?php

namespace App\Support\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\PartyRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters of the staff Invoices list and its export (spec 016 FR-010,
 * FR-013): a Cairo date range on the issue time (default the last 30 days),
 * the party, the derived status, and a search on the invoice number (which
 * starts with the order reference) or the customer's reference or name.
 */
final readonly class InvoiceListQuery
{
    /** The credit notes' gross per invoice, as SQL (status and the `credited_sum` column). */
    public const CREDITED_SQL = '(SELECT COALESCE(SUM(c.gross_amount), 0) FROM credit_note c WHERE c.invoice_id = tax_invoice.invoice_id)';

    public function __construct(
        public string $from,
        public string $to,
        public ?PartyRole $party,
        public ?InvoiceStatus $status,
        public ?string $q,
    ) {}

    public function apply(Builder $query): Builder
    {
        $query->where('tax_invoice.issued_at', '>=', CarbonImmutable::parse($this->from, 'Africa/Cairo')->startOfDay())
            ->where('tax_invoice.issued_at', '<', CarbonImmutable::parse($this->to, 'Africa/Cairo')->addDay()->startOfDay());

        if ($this->party !== null) {
            $query->where('tax_invoice.party_role', $this->party->value);
        }
        match ($this->status) {
            InvoiceStatus::ISSUED => $query->whereRaw(self::CREDITED_SQL.' = 0'),
            InvoiceStatus::PARTLY_CREDITED => $query->whereRaw(self::CREDITED_SQL.' > 0')->whereRaw(self::CREDITED_SQL.' < tax_invoice.gross_amount'),
            InvoiceStatus::CREDITED => $query->whereRaw(self::CREDITED_SQL.' >= tax_invoice.gross_amount'),
            null => null,
        };
        if (filled($this->q)) {
            $term = trim((string) $this->q);
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn (Builder $w) => $w
                ->where('tax_invoice.invoice_no', 'ilike', $like)
                ->orWhereHas('customer', fn (Builder $c) => $c->where('display_ref', $term)->orWhere('full_name', 'ilike', $like)));
        }

        return $query;
    }

    /** @return array<string, mixed> for the export's audit row */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'party' => $this->party?->value, 'status' => $this->status?->value, 'q' => $this->q];
    }
}
