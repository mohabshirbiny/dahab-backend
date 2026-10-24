<?php

namespace App\Http\Requests\Customer\Order;

use App\Enums\IdentityDocumentKind;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** The buyer names someone else to collect (spec 014 FR-018, research R14). */
#[OA\Schema(
    schema: 'NameProxyRequest',
    required: ['name', 'phone', 'id_kind', 'id_upload_token', 'authorisation_id', 'authorisation_accepted'],
    properties: [
        new OA\Property(property: 'name', type: 'string', minLength: 2, maxLength: 120, description: 'Full name as written on their ID'),
        new OA\Property(property: 'phone', type: 'string', pattern: '^\+[1-9]\d{7,14}$', example: '+201001234567'),
        new OA\Property(property: 'id_kind', type: 'string', enum: ['egyptian_id', 'passport'], description: 'The kind of their ID, as at sign-up'),
        new OA\Property(property: 'id_upload_token', type: 'string', description: 'upload_token of purpose proxy_id: the front of their ID, or the passport photo page'),
        new OA\Property(property: 'id_back_upload_token', type: 'string', nullable: true, description: 'upload_token of purpose proxy_id: the back of their ID — required for egyptian_id, optional for a passport'),
        new OA\Property(property: 'authorisation_id', type: 'integer', description: 'legal_doc_id of the current collection_proxy_authorisation (GET /reference/legal-documents/collection_proxy_authorisation)'),
        new OA\Property(property: 'authorisation_accepted', type: 'boolean', enum: [true]),
    ],
)]
class NameProxyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => preg_replace('/[\s\-()]/', '', $this->input('phone'))]);
        }
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim((string) preg_replace('/\s+/', ' ', $this->input('name')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'id_kind' => ['required', 'string', 'in:egyptian_id,passport'],
            'id_upload_token' => ['required', 'string', 'max:100'],
            'id_back_upload_token' => ['nullable', 'required_if:id_kind,egyptian_id', 'string', 'max:100', 'different:id_upload_token'],
            'authorisation_id' => ['required', 'integer'],
            'authorisation_accepted' => ['required', 'accepted'],
        ];
    }

    public function idKind(): IdentityDocumentKind
    {
        return IdentityDocumentKind::from((string) $this->validated('id_kind'));
    }
}
