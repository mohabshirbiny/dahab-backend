<?php

namespace App\Http\Resources\Staff;

use App\Http\Resources\Invoices\InvoiceFields;
use App\Models\CreditNote;
use App\Models\TaxInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** A tax invoice on the Invoices page (spec 016 FR-010, FR-012); the detail adds its lines and credit notes. */
#[OA\Schema(
    schema: 'StaffInvoice',
    required: ['id', 'number', 'party', 'order_id', 'order_ref', 'issued_at', 'net', 'vat', 'gross', 'credited', 'remaining', 'status', 'document_ready', 'customer'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'number', type: 'string', example: 'DH-2026-000123-S'),
        new OA\Property(property: 'party', type: 'string', enum: ['seller', 'buyer']),
        new OA\Property(property: 'order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'order_ref', type: 'string', example: 'DH-2026-000123'),
        new OA\Property(property: 'issued_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'net', type: 'string', example: '600.0000', description: "Seller: Dahab's commission; buyer: the price paid"),
        new OA\Property(property: 'vat', type: 'string', example: '84.0000', description: '0 on a buyer invoice'),
        new OA\Property(property: 'gross', type: 'string', example: '684.0000'),
        new OA\Property(property: 'credited', type: 'string', description: 'Sum of its credit notes'),
        new OA\Property(property: 'remaining', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['issued', 'partly_credited', 'credited'], description: 'Derived from the credit notes — never a Tax Authority status'),
        new OA\Property(property: 'document_ready', type: 'boolean'),
        new OA\Property(property: 'customer', type: 'object', description: '{id, display_ref, name}'),
        new OA\Property(property: 'vat_rate', type: 'string', description: 'Detail only'),
        new OA\Property(property: 'lines', type: 'object', description: 'Detail only: the settlement lines snapshot (piece, weight, rate, gold value, making charge, asking price, subtotal; seller: commission pct, minimum, paid to wallet)'),
        new OA\Property(property: 'issuer', type: 'object', nullable: true, description: "Detail only: Dahab's details copied onto the invoice; null until configured"),
        new OA\Property(property: 'party_details', type: 'object', nullable: true, description: 'Detail only: {full_name, display_ref} copied when the document was made'),
        new OA\Property(property: 'creditable', type: 'boolean', description: 'Detail only: a seller invoice with something left to credit'),
        new OA\Property(property: 'credit_notes', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffCreditNote'), description: 'Detail only'),
    ],
)]
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxInvoice $i */
        $i = $this->resource;

        $out = InvoiceFields::summary($i) + [
            'customer' => ['id' => $i->customer_id, 'display_ref' => $i->customer?->display_ref, 'name' => $i->customer?->full_name],
        ];

        if ($i->relationLoaded('creditNotes')) {
            $out += InvoiceFields::detail($i) + [
                'party_details' => $i->party,
                'credit_notes' => $i->creditNotes->map(fn (CreditNote $n) => InvoiceFields::creditNote($n, $i->invoice_no)
                    + ['issued_by' => ['id' => $n->issued_by, 'name' => $n->issuedBy?->full_name]])->all(),
            ];
        }

        return $out;
    }
}
