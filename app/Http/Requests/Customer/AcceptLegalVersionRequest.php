<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AcceptLegalVersionRequest',
    required: ['legal_doc_id'],
    properties: [
        new OA\Property(property: 'legal_doc_id', type: 'integer', description: 'The `legal_doc_id` from `legal_acceptance_required`: the live version the customer was shown'),
    ],
)]
#[OA\Schema(
    schema: 'LegalAcceptanceRequired',
    description: 'A legal document the customer must accept before any state-changing request is allowed (spec 019). Empty list when nothing is owed.',
    properties: [
        new OA\Property(property: 'code', type: 'string', enum: ['terms', 'privacy']),
        new OA\Property(property: 'version', type: 'integer'),
        new OA\Property(property: 'legal_doc_id', type: 'integer', description: 'Read its text at /reference/legal-documents/{code}, send this id to accept it'),
        new OA\Property(property: 'reason', type: 'string', enum: ['first', 'material'], description: 'first: nothing accepted yet; material: a material version is newer than the last one accepted'),
    ],
)]
class AcceptLegalVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'legal_doc_id' => ['required', 'integer', 'min:1', 'max:32767'],
        ];
    }
}
