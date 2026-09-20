<?php

namespace App\Http\Resources\Customer;

use App\Models\IdentityDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'IdentityDocument',
    description: 'A customer\'s own identity document submission. Never includes the storage reference or the image.',
    properties: [
        new OA\Property(property: 'document_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'doc_kind', type: 'string', enum: ['egyptian_id', 'passport']),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'approved', 'rejected']),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
class IdentityDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var IdentityDocument $d */
        $d = $this->resource;

        return [
            'document_id' => $d->document_id,
            'doc_kind' => $d->doc_kind->value,
            'status' => $d->status->value,
            'created_at' => $d->created_at->toIso8601String(),
        ];
    }
}
