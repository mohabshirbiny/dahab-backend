<?php

namespace App\Http\Resources\Customer;

use App\Http\Resources\Invoices\InvoiceFields;
use App\Models\CreditNote;
use App\Models\TaxInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** The customer's own tax invoice (spec 016 FR-015); the detail adds its lines, Dahab's details and credit notes. */
#[OA\Schema(
    schema: 'CustomerInvoice',
    required: ['id', 'number', 'party', 'order_id', 'order_ref', 'issued_at', 'net', 'vat', 'gross', 'credited', 'remaining', 'status', 'document_ready'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'number', type: 'string', example: 'DH-2026-000123-S'),
        new OA\Property(property: 'party', type: 'string', enum: ['seller', 'buyer'], description: 'seller = a piece you sold; buyer = a piece you bought'),
        new OA\Property(property: 'order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'order_ref', type: 'string'),
        new OA\Property(property: 'issued_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'net', type: 'string'),
        new OA\Property(property: 'vat', type: 'string'),
        new OA\Property(property: 'gross', type: 'string'),
        new OA\Property(property: 'credited', type: 'string'),
        new OA\Property(property: 'remaining', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['issued', 'partly_credited', 'credited']),
        new OA\Property(property: 'document_ready', type: 'boolean'),
        new OA\Property(property: 'piece', type: 'object', description: 'List rows: {category, karat, piece_type_en, piece_type_ar, weight_g, subtotal}'),
        new OA\Property(property: 'vat_rate', type: 'string', description: 'Detail only'),
        new OA\Property(property: 'lines', type: 'object', description: 'Detail only'),
        new OA\Property(property: 'issuer', type: 'object', nullable: true, description: 'Detail only'),
        new OA\Property(property: 'credit_notes', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerCreditNote'), description: 'Detail only'),
    ],
)]
#[OA\Schema(
    schema: 'CustomerCreditNote',
    required: ['id', 'number', 'invoice_id', 'invoice_number', 'reason', 'net', 'vat', 'gross', 'issued_at', 'document_ready'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'number', type: 'string', example: 'CN-2026-000001'),
        new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'invoice_number', type: 'string'),
        new OA\Property(property: 'reason', type: 'string'),
        new OA\Property(property: 'net', type: 'string'),
        new OA\Property(property: 'vat', type: 'string'),
        new OA\Property(property: 'gross', type: 'string'),
        new OA\Property(property: 'issued_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'document_ready', type: 'boolean'),
    ],
)]
class CustomerInvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxInvoice $i */
        $i = $this->resource;
        $l = $i->lines;
        $out = InvoiceFields::summary($i);

        if (! $i->relationLoaded('creditNotes')) {
            return $out + ['piece' => [
                'category' => $l['category'] ?? null, 'karat' => $l['karat'] ?? null, 'piece_type_en' => $l['piece_type_en'] ?? null,
                'piece_type_ar' => $l['piece_type_ar'] ?? null, 'weight_g' => $l['weight_g'] ?? null, 'subtotal' => $l['subtotal'] ?? null,
            ]];
        }

        $detail = InvoiceFields::detail($i);
        unset($detail['creditable']);

        return $out + $detail + [
            'credit_notes' => $i->creditNotes->map(fn (CreditNote $n) => InvoiceFields::creditNote($n, $i->invoice_no))->all(),
        ];
    }
}
