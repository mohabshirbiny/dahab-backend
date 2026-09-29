<?php

namespace App\Http\Requests\Identity;

use App\Enums\UploadPurpose;
use App\Http\Middleware\EnsureCustomerStanding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateUploadRequest',
    description: 'multipart/form-data',
    required: ['purpose', 'file'],
    properties: [
        new OA\Property(property: 'purpose', type: 'string', enum: ['identity', 'topup_receipt'], description: '`identity` is open to customers waiting for verification; `topup_receipt` (spec 009) needs a verified, non-suspended customer (403 verification_required / account_suspended).'),
        new OA\Property(property: 'file', type: 'string', format: 'binary', description: '`identity`: JPEG, PNG or WebP image. `topup_receipt`: the same images or a PDF. Size limited by `dahab-identity.max_upload_kb`.'),
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
        $purpose = UploadPurpose::tryFrom((string) $this->input('purpose')) ?? UploadPurpose::IDENTITY;

        return [
            'purpose' => ['required', Rule::enum(UploadPurpose::class)],
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', $purpose->allowedMimes()),
                'max:'.config('dahab-identity.max_upload_kb'),
            ],
        ];
    }

    /**
     * A top-up receipt is part of adding money, so it takes the trade gate
     * (spec 009 FR-008, FR-018a, research R6); the route stays open for
     * identity uploads by customers waiting for verification. Runs before
     * anything is stored.
     */
    protected function passedValidation(): void
    {
        if ($this->validated('purpose') === UploadPurpose::TOPUP_RECEIPT->value) {
            EnsureCustomerStanding::assert($this->user('customer'), 'trade');
        }
    }
}
