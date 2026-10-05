<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credit note (spec 016): staff correct a seller's tax invoice, in part or
 * in full, with a reason; one balanced `credit_note` entry refunds Dahab's
 * commission and VAT to the seller. Never above what is left of the invoice
 * (row lock + DH012); append-only except the issuer and document, set once.
 * The seller reads their own (forced RLS).
 *
 * @property string $credit_note_id
 * @property string $credit_note_no
 * @property string $invoice_id
 * @property string $customer_id
 * @property string $net_amount
 * @property string $vat_amount
 * @property string $gross_amount
 * @property string $reason
 * @property string $issued_by
 * @property string $ledger_txn_id
 * @property array<string, string>|null $issuer
 * @property CarbonImmutable $issued_at
 * @property string|null $storage_ref
 * @property CarbonImmutable|null $document_at
 */
class CreditNote extends Model
{
    use HasUuids;

    protected $table = 'credit_note';

    protected $primaryKey = 'credit_note_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uO';

    protected function casts(): array
    {
        return [
            'net_amount' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'gross_amount' => 'decimal:4',
            'issuer' => 'array',
            'issued_at' => 'immutable_datetime',
            'document_at' => 'immutable_datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TaxInvoice::class, 'invoice_id', 'invoice_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'issued_by', 'staff_id');
    }

    public function documentReady(): bool
    {
        return $this->storage_ref !== null;
    }
}
