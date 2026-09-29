<?php

namespace App\Http\Requests\Dashboard\ReceivingAccount;

use App\Models\ReceivingAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardUpdateReceivingAccount',
    description: 'Any editable field of DashboardStoreReceivingAccount except `method` (prohibited: an account\'s method never changes). Details the account\'s method requires cannot be cleared.',
    properties: [
        new OA\Property(property: 'label', type: 'string', maxLength: 80),
        new OA\Property(property: 'bank_name', type: 'string', nullable: true),
        new OA\Property(property: 'account_holder', type: 'string', nullable: true),
        new OA\Property(property: 'account_number', type: 'string', nullable: true),
        new OA\Property(property: 'iban', type: 'string', nullable: true),
        new OA\Property(property: 'instapay_address', type: 'string', nullable: true),
        new OA\Property(property: 'wallet_number', type: 'string', nullable: true),
        new OA\Property(property: 'daily_limit', type: 'string', nullable: true),
        new OA\Property(property: 'provider_fee_percent', type: 'string', nullable: true),
        new OA\Property(property: 'customer_note', type: 'string', nullable: true),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ],
)]
class UpdateReceivingAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['method' => ['prohibited'], ...StoreReceivingAccountRequest::detailRules(null, partial: true)];
    }

    /** A detail the account's method needs cannot be emptied. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $account = ReceivingAccount::query()->find((int) $this->route('account'));
            if ($account === null) {
                return;
            }
            $required = match ($account->method->value) {
                'bank_transfer' => ['bank_name', 'account_holder', 'account_number'],
                'instapay' => ['instapay_address'],
                'vodafone_cash' => ['wallet_number'],
            };
            foreach ($required as $field) {
                if ($this->exists($field) && blank($this->input($field))) {
                    $validator->errors()->add($field, "A {$account->method->label()} account needs this.");
                }
            }
        }];
    }
}
