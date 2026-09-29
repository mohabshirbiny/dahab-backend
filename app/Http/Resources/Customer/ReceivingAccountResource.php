<?php

namespace App\Http\Resources\Customer;

use App\Models\ReceivingAccount;
use App\Support\TopUpMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CustomerReceivingAccount',
    description: 'One of Dahab\'s accounts to send money to (spec 009). `details` are ordered per method; the app labels each key. `daily_limit` and `provider_fee_percent` are display only.',
    required: ['id', 'method', 'label', 'details', 'daily_limit', 'provider_fee_percent', 'note'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'method', type: 'string', enum: ['bank_transfer', 'instapay', 'vodafone_cash']),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'details', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'key', type: 'string', enum: ['bank_name', 'account_holder', 'account_number', 'iban', 'instapay_address', 'wallet_number']),
            new OA\Property(property: 'value', type: 'string'),
        ], type: 'object')),
        new OA\Property(property: 'daily_limit', type: 'string', nullable: true, example: '70000.0000'),
        new OA\Property(property: 'provider_fee_percent', type: 'string', nullable: true, example: '0.500'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
    ],
)]
class ReceivingAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ReceivingAccount $a */
        $a = $this->resource;

        return [
            'id' => $a->receiving_account_id,
            'method' => $a->method->value,
            'label' => $a->label,
            'details' => $a->detailRows(),
            'daily_limit' => TopUpMoney::format($a->daily_limit),
            'provider_fee_percent' => $a->provider_fee_percent === null ? null : bcadd((string) $a->provider_fee_percent, '0', 3),
            'note' => $a->customer_note,
        ];
    }
}
