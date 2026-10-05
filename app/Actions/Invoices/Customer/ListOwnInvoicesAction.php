<?php

namespace App\Actions\Invoices\Customer;

use App\Actions\Invoices\ListInvoicesAction;
use App\Enums\PartyRole;
use App\Models\TaxInvoice;
use App\Support\Listings\ListingCursor;
use App\Support\Withdrawals\KeysetPage;
use Illuminate\Support\Collection;

/**
 * A customer's own tax invoices (spec 016 FR-015, FR-016): newest first,
 * sold, bought or both. Row-level security shows only their rows; the
 * customer filter keeps the query honest without it. Not audited.
 */
final class ListOwnInvoicesAction
{
    /** @return array{rows: Collection<int, TaxInvoice>, next_cursor: string|null} */
    public function handle(string $customerId, ?PartyRole $role, ?ListingCursor $cursor, int $perPage): array
    {
        $query = TaxInvoice::query()->where('tax_invoice.customer_id', $customerId);
        if ($role !== null) {
            $query->where('tax_invoice.party_role', $role->value);
        }

        $page = KeysetPage::byTime($query, 'tax_invoice', 'issued_at', 'invoice_id', true, $cursor, $perPage);
        ListInvoicesAction::withCredited($page['rows']);

        return $page;
    }
}
