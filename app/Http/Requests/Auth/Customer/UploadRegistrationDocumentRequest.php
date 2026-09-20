<?php

namespace App\Http\Requests\Auth\Customer;

use App\Enums\IdentityDocumentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Step 5: multipart upload of identity images.
 *
 * Egyptian ID → front + back required.
 * Passport    → front required, back optional.
 *
 * Image MIME/size limits come from `config/dahab-identity.php` (reused for
 * both authenticated uploads and registration uploads).
 */
#[OA\Schema(
    schema: 'UploadRegistrationDocumentRequest',
    description: 'multipart/form-data',
    required: ['registration_ref', 'document_type', 'front'],
    properties: [
        new OA\Property(property: 'registration_ref', type: 'string', format: 'uuid'),
        new OA\Property(property: 'document_type', type: 'string', enum: ['egyptian_id', 'passport']),
        new OA\Property(property: 'front', type: 'string', format: 'binary'),
        new OA\Property(property: 'back', type: 'string', format: 'binary', nullable: true, description: 'Required for egyptian_id, ignored for passport unless a back page exists'),
    ],
)]
class UploadRegistrationDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $imageRules = [
            'file',
            'mimes:'.implode(',', config('dahab-identity.allowed_mimes')),
            'max:'.config('dahab-identity.max_upload_kb'),
        ];

        return [
            'registration_ref' => ['required', 'string', 'uuid'],
            'document_type' => ['required', Rule::enum(IdentityDocumentKind::class)],
            'front' => array_merge(['required'], $imageRules),
            'back' => array_merge([
                Rule::requiredIf(fn () => $this->input('document_type') === IdentityDocumentKind::EGYPTIAN_ID->value),
                'nullable',
            ], $imageRules),
        ];
    }
}
