<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PartyRole;
use App\Models\Concerns\BelongsToOrder;
use App\Support\Pricing\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tax invoice (spec 016): issued automatically inside the pay-balance
 * settlement, one per order per party — the seller's for Dahab's commission
 * and VAT, the buyer's for the price paid with VAT 0. Never edited: the
 * snapshot and document columns are each set once, a correction is a
 * credit note (DH012). A customer reads their own (forced RLS). Nothing is
 * filed with the Tax Authority (`eta_reference` stays NULL).
 *
 * @property string $invoice_id
 * @property string $invoice_no
 * @property string $order_id
 * @property PartyRole $party_role
 * @property string $customer_id
 * @property string $net_amount
 * @property string $vat_amount
 * @property string $gross_amount
 * @property string $vat_rate
 * @property array<string, mixed> $lines
 * @property array<string, string>|null $issuer
 * @property array<string, string>|null $party
 * @property CarbonImmutable $issued_at
 * @property string|null $storage_ref
 * @property CarbonImmutable|null $document_at
 * @property string|null $credited_sum the credit notes' gross, when selected by a query
 */
class TaxInvoice extends Model
{
    use BelongsToOrder;

    protected $table = 'tax_invoice';

    protected $primaryKey = 'invoice_id';

    protected function casts(): array
    {
        return [
            'party_role' => PartyRole::class,
            'net_amount' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'gross_amount' => 'decimal:4',
            'vat_rate' => 'decimal:3',
            'lines' => 'array',
            'issuer' => 'array',
            'party' => 'array',
            'issued_at' => 'immutable_datetime',
            'document_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class, 'invoice_id', 'invoice_id')->orderBy('issued_at')->orderBy('credit_note_id');
    }

    /** Only a seller invoice carries a Dahab charge to correct (spec 016 Clarifications). */
    public function isCreditable(): bool
    {
        return $this->party_role === PartyRole::SELLER;
    }

    /** The credit notes' gross: from the query's `credited_sum` when present, else loaded. */
    public function credited(): string
    {
        if (array_key_exists('credited_sum', $this->attributes)) {
            return Money::fixed4((string) ($this->attributes['credited_sum'] ?? '0'));
        }

        $sum = '0';
        foreach ($this->creditNotes as $note) {
            $sum = Money::add($sum, (string) $note->gross_amount);
        }

        return Money::fixed4($sum);
    }

    public function remaining(): string
    {
        return Money::fixed4(Money::sub((string) $this->gross_amount, $this->credited()));
    }

    public function status(): InvoiceStatus
    {
        return InvoiceStatus::of($this->credited(), (string) $this->gross_amount);
    }

    public function documentReady(): bool
    {
        return $this->storage_ref !== null;
    }
}
