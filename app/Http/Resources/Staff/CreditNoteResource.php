<?php

namespace App\Http\Resources\Staff;

use App\Http\Resources\Invoices\InvoiceFields;
use App\Models\CreditNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** A credit note on the Invoices page (spec 016 FR-014): what it reverses, why, how much, by whom. */
#[OA\Schema(
    schema: 'StaffCreditNote',
    required: ['id', 'number', 'invoice_id', 'invoice_number', 'reason', 'net', 'vat', 'gross', 'issued_at', 'document_ready', 'issued_by'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'number', type: 'string', example: 'CN-2026-000001'),
        new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'invoice_number', type: 'string', example: 'DH-2026-000123-S'),
        new OA\Property(property: 'reason', type: 'string'),
        new OA\Property(property: 'net', type: 'string', example: '263.1579'),
        new OA\Property(property: 'vat', type: 'string', example: '36.8421'),
        new OA\Property(property: 'gross', type: 'string', example: '300.0000'),
        new OA\Property(property: 'issued_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'document_ready', type: 'boolean'),
        new OA\Property(property: 'issued_by', type: 'object', description: '{id, name}'),
        new OA\Property(property: 'customer', type: 'object', description: '{id, display_ref, name}'),
    ],
)]
class CreditNoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CreditNote $n */
        $n = $this->resource;

        return InvoiceFields::creditNote($n) + [
            'issued_by' => ['id' => $n->issued_by, 'name' => $n->issuedBy?->full_name],
            'customer' => ['id' => $n->customer_id, 'display_ref' => $n->customer?->display_ref, 'name' => $n->customer?->full_name],
        ];
    }
}
