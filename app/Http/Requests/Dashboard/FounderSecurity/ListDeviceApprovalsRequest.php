<?php

namespace App\Http\Requests\Dashboard\FounderSecurity;

use App\Models\FounderDeviceApproval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDeviceApprovalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in([...FounderDeviceApproval::STATUSES, 'all'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function status(): string
    {
        return (string) $this->validated('status', FounderDeviceApproval::STATUS_PENDING);
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 20);
    }
}
