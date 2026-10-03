<?php

namespace App\Http\Resources\Customer;

use App\Models\WithdrawalConfirmation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'WithdrawalConfirmation',
    description: 'The email second-check of a withdrawal (spec 013 R5). Never the token.',
    required: ['id', 'state', 'amount', 'account', 'expires_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'state', type: 'string', enum: ['sent', 'confirmed', 'used', 'expired', 'replaced']),
        new OA\Property(property: 'amount', type: 'string', example: '42000.0000'),
        new OA\Property(property: 'account', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'bank_name', type: 'string'),
            new OA\Property(property: 'number_masked', type: 'string', example: '•••• 4417'),
        ], type: 'object'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'email_masked', type: 'string', nullable: true, example: 'm•••@email.com', description: 'Only when the link was just sent'),
    ],
)]
class WithdrawalConfirmationResource extends JsonResource
{
    public ?string $email = null;

    public function toArray(Request $request): array
    {
        /** @var WithdrawalConfirmation $c */
        $c = $this->resource;

        return [
            'id' => $c->confirmation_id,
            'state' => $c->state(),
            'amount' => bcadd((string) $c->amount, '0', 4),
            'account' => [
                'id' => $c->payout_account_id,
                'bank_name' => $c->account?->bank_name,
                'number_masked' => $c->account?->masked(),
            ],
            'expires_at' => $c->expires_at->toIso8601String(),
            'email_masked' => $this->email === null ? null : self::maskEmail($this->email),
        ];
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
