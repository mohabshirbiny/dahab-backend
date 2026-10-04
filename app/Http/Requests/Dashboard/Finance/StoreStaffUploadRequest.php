<?php

namespace App\Http\Requests\Dashboard\Finance;

use App\Enums\UploadPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A staff upload (spec 015 research R5): only the staff purposes. */
class StoreStaffUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $purpose = UploadPurpose::BANK_MOVEMENT_PROOF;

        return [
            'purpose' => ['required', 'string', Rule::in([UploadPurpose::BANK_MOVEMENT_PROOF->value])],
            'file' => ['required', 'file', 'mimes:'.implode(',', $purpose->allowedMimes()), 'max:'.$purpose->maxKilobytes()],
        ];
    }

    public function purpose(): UploadPurpose
    {
        return UploadPurpose::from($this->validated('purpose'));
    }
}
