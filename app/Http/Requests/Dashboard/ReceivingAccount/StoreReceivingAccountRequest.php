<?php

namespace App\Http\Requests\Dashboard\ReceivingAccount;

use App\Enums\TopUpMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardStoreReceivingAccount',
    required: ['method', 'label'],
    properties: [
        new OA\Property(property: 'method', type: 'string', enum: ['bank_transfer', 'instapay', 'vodafone_cash'], description: 'Never changes after creation'),
        new OA\Property(property: 'label', type: 'string', maxLength: 80, description: 'Staff-facing name'),
        new OA\Property(property: 'bank_name', type: 'string', maxLength: 80, nullable: true, description: 'Required for bank_transfer'),
        new OA\Property(property: 'account_holder', type: 'string', maxLength: 120, nullable: true, description: 'Required for bank_transfer'),
        new OA\Property(property: 'account_number', type: 'string', nullable: true, description: 'Required for bank_transfer; digits and spaces, 6–34'),
        new OA\Property(property: 'iban', type: 'string', nullable: true, example: 'EG380019000500000000263180002'),
        new OA\Property(property: 'instapay_address', type: 'string', maxLength: 120, nullable: true, description: 'Required for instapay'),
        new OA\Property(property: 'wallet_number', type: 'string', nullable: true, example: '01012345678', description: 'Required for vodafone_cash'),
        new OA\Property(property: 'daily_limit', type: 'string', nullable: true, description: 'Display only'),
        new OA\Property(property: 'provider_fee_percent', type: 'string', nullable: true, description: 'Display only, 0–100'),
        new OA\Property(property: 'customer_note', type: 'string', maxLength: 300, nullable: true),
        new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, maximum: 999),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ],
)]
class StoreReceivingAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'method' => ['required', Rule::enum(TopUpMethod::class)],
            ...self::detailRules($this->input('method')),
        ];
    }

    public function topUpMethod(): TopUpMethod
    {
        return TopUpMethod::from($this->validated('method'));
    }

    /**
     * The per-method details rules, matching the database CHECK
     * `receiving_account_details` (spec 009 data-model).
     *
     * @return array<string, list<mixed>>
     */
    public static function detailRules(mixed $method, bool $partial = false): array
    {
        $required = fn (string $forMethod) => $partial ? 'sometimes' : ($method === $forMethod ? 'required' : 'nullable');
        $keep = $partial ? ['sometimes'] : [];

        return [
            'label' => [$partial ? 'sometimes' : 'required', 'string', 'max:80'],
            'bank_name' => [$required('bank_transfer'), 'nullable', 'string', 'max:80'],
            'account_holder' => [$required('bank_transfer'), 'nullable', 'string', 'max:120'],
            'account_number' => [$required('bank_transfer'), 'nullable', 'string', 'regex:/^[0-9 ]{6,34}$/'],
            'iban' => [...$keep, 'nullable', 'string', 'regex:/^EG[0-9]{27}$/'],
            'instapay_address' => [$required('instapay'), 'nullable', 'string', 'max:120'],
            'wallet_number' => [$required('vodafone_cash'), 'nullable', 'string', 'regex:/^01[0125][0-9]{8}$/'],
            'daily_limit' => [...$keep, 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999.99'],
            'provider_fee_percent' => [...$keep, 'nullable', 'numeric', 'decimal:0,3', 'between:0,100'],
            'customer_note' => [...$keep, 'nullable', 'string', 'max:300'],
            'sort_order' => [...$keep, 'integer', 'between:0,999'],
            'is_active' => [...$keep, 'boolean'],
        ];
    }
}
