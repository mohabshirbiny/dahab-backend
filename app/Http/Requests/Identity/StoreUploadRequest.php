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
        new OA\Property(property: 'purpose', type: 'string', enum: ['identity', 'topup_receipt', 'listing_photo', 'listing_video', 'listing_invoice', 'stone_certificate'], description: '`identity` is open to customers waiting for verification; every other purpose needs a verified, non-suspended customer (403 verification_required / account_suspended). Types and sizes: `identity` and `listing_photo` JPEG/PNG/WebP up to 8 MB; `topup_receipt`, `listing_invoice` and `stone_certificate` also PDF, up to 8 MB; `listing_video` MP4/MOV/WebM up to 50 MB (spec 010).'),
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
                'max:'.$purpose->maxKilobytes(),
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
        // Spec 010: listing media is part of selling, so it takes the trade gate too.
        if (UploadPurpose::from($this->validated('purpose'))->requiresTrade()) {
            EnsureCustomerStanding::assert($this->user('customer'), 'trade');
        }
    }
}
