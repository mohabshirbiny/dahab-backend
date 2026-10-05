<?php

namespace App\Support\Invoices;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Invoices page figures (spec 016 FR-011) for a Cairo window: invoices
 * issued (both parties), net invoiced (seller invoices — Dahab's commission),
 * VAT collected (seller VAT less credit-note VAT) and the credit notes issued
 * in the window (count and gross). One aggregate per table.
 */
final class InvoiceFigures
{
    /** @return array{issued_count: int, net_invoiced: string, vat_collected: string, credit_notes_count: int, credit_notes_amount: string} */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $inv = DB::selectOne("
            SELECT count(*) AS n,
                   COALESCE(SUM(net_amount) FILTER (WHERE party_role = 'seller'), 0)::numeric(18,4)::text AS net,
                   COALESCE(SUM(vat_amount) FILTER (WHERE party_role = 'seller'), 0)::numeric(18,4) AS vat
              FROM tax_invoice WHERE issued_at >= ? AND issued_at < ?", [$from, $to]);
        $cn = DB::selectOne('
            SELECT count(*) AS n, COALESCE(SUM(gross_amount), 0)::numeric(18,4)::text AS gross,
                   COALESCE(SUM(vat_amount), 0)::numeric(18,4) AS vat
              FROM credit_note WHERE issued_at >= ? AND issued_at < ?', [$from, $to]);

        return [
            'issued_count' => (int) $inv->n,
            'net_invoiced' => (string) $inv->net,
            'vat_collected' => bcsub((string) $inv->vat, (string) $cn->vat, 4),
            'credit_notes_count' => (int) $cn->n,
            'credit_notes_amount' => (string) $cn->gross,
        ];
    }

    /** @return array<string, mixed> the current Cairo month */
    public function month(): array
    {
        $start = CarbonImmutable::now('Africa/Cairo')->startOfMonth();

        return $this->between($start, $start->addMonth());
    }

    /** @return array<string, mixed> a Cairo date range, inclusive */
    public function period(string $from, string $to): array
    {
        return $this->between(CarbonImmutable::parse($from, 'Africa/Cairo')->startOfDay(), CarbonImmutable::parse($to, 'Africa/Cairo')->addDay()->startOfDay());
    }
}
