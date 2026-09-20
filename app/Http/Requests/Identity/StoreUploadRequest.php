<?php

namespace App\Http\Requests\Identity;

use App\Enums\UploadPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateUploadRequest',
    description: 'multipart/form-data',
    required: ['purpose', 'file'],
    properties: [
        new OA\Property(property: 'purpose', type: 'string', enum: ['identity']),
        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'JPEG, PNG or WebP image; size limited by `dahab-identity.max_upload_kb`.'),
    ],
)]
class StoreUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purpose' => ['required', Rule::enum(UploadPurpose::class)],
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', config('dahab-identity.allowed_mimes')),
                'max:'.config('dahab-identity.max_upload_kb'),
            ],
        ];
    }
}
