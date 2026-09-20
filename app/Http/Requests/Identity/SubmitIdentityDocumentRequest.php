<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * Post-login identity submission (and resubmission).
 *
 * `front_upload_token` is required; `back_upload_token` is required for
 * Egyptian ID and optional for passport. Backwards-compatible alias
 * `upload_token` is accepted and mapped to `front_upload_token`.
 *
 * `doc_kind` is checked in the Action so an unsupported kind is refused
 * with a stable `unsupported_doc_kind` code rather than a generic
 * `validation_failed`.
 */
#[OA\Schema(
    schema: 'SubmitIdentityDocumentRequest',
    required: ['doc_kind', 'front_upload_token'],
    properties: [
        new OA\Property(property: 'doc_kind', type: 'string', enum: ['egyptian_id', 'passport']),
        new OA\Property(property: 'front_upload_token', type: 'string', description: 'From POST /customer/me/uploads with purpose identity.'),
        new OA\Property(property: 'back_upload_token', type: 'string', nullable: true, description: 'Required for egyptian_id.'),
    ],
)]
class SubmitIdentityDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function prepareForValidation(): void
    {
        if ($this->missing('front_upload_token') && $this->filled('upload_token')) {
            $this->merge(['front_upload_token' => $this->input('upload_token')]);
        }
    }

    public function rules(): array
    {
        return [
            'doc_kind' => ['required', 'string', 'max:32'],
            'front_upload_token' => ['required', 'string', 'max:255'],
            'back_upload_token' => ['nullable', 'string', 'max:255'],
        ];
    }
}
