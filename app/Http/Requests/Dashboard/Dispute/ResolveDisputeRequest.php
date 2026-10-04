<?php

namespace App\Http\Requests\Dashboard\Dispute;

use App\Enums\CompensationReason;
use App\Enums\DisputeOutcome;
use App\Enums\SuspendedReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use OpenApi\Attributes as OA;

/** Resolve a dispute — always with a reply (spec 014 FR-010–FR-016). */
#[OA\Schema(
    schema: 'ResolveDisputeRequest',
    required: ['outcome', 'reply'],
    properties: [
        new OA\Property(property: 'outcome', type: 'string', enum: ['resume', 'against_sale'], description: 'against_sale: before payment only, needs order.refund'),
        new OA\Property(property: 'reply', type: 'string', minLength: 10, maxLength: 2000, description: 'Sent to the customer who raised it'),
        new OA\Property(property: 'compensation', type: 'object', nullable: true, description: 'Needs compensation.pay; capped unless compensation.uncapped', properties: [
            new OA\Property(property: 'party', type: 'string', enum: ['buyer', 'seller']),
            new OA\Property(property: 'amount', type: 'string', example: '500.00'),
            new OA\Property(property: 'reason', type: 'string', enum: ['igi_delay', 'dahab_mistake', 'wasted_trip', 'dispute_settlement', 'goodwill']),
            new OA\Property(property: 'note', type: 'string', minLength: 10, maxLength: 1000),
        ]),
        new OA\Property(property: 'suspend_seller', type: 'object', nullable: true, description: 'With against_sale only; needs customer.suspend', properties: [
            new OA\Property(property: 'reason', type: 'string', enum: ['piece_misrepresented', 'off_platform_dealing', 'repeated_disputes', 'reported_by_users', 'identity_unconfirmed', 'customer_request', 'other']),
            new OA\Property(property: 'note', type: 'string', nullable: true, maxLength: 1000),
        ]),
    ],
)]
class ResolveDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', Rule::enum(DisputeOutcome::class)],
            'reply' => ['required', 'string', 'min:10', 'max:2000'],
            'compensation' => ['sometimes', 'nullable', 'array'],
            'compensation.party' => ['required_with:compensation', 'string', 'in:buyer,seller'],
            'compensation.amount' => ['required_with:compensation', 'string', 'regex:/^\d{1,12}(\.\d{1,4})?$/'],
            'compensation.reason' => ['required_with:compensation', 'string', Rule::enum(CompensationReason::class)],
            'compensation.note' => ['required_with:compensation', 'string', 'min:10', 'max:1000'],
            'suspend_seller' => ['sometimes', 'nullable', 'array'],
            'suspend_seller.reason' => ['required_with:suspend_seller', 'string',
                Rule::in(array_map(fn (SuspendedReason $r) => $r->value, SuspendedReason::staffChoices()))],
            'suspend_seller.note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($this->input('compensation') !== null && ! $v->errors()->has('compensation.amount')
                && bccomp((string) $this->input('compensation.amount'), '0', 4) <= 0) {
                $v->errors()->add('compensation.amount', 'The amount must be more than zero.');
            }
            if ($this->input('suspend_seller') !== null && $this->input('outcome') !== DisputeOutcome::AGAINST_SALE->value) {
                $v->errors()->add('suspend_seller', 'The seller can be suspended only when the dispute goes against the sale.');
            }
        }];
    }

    public function outcome(): DisputeOutcome
    {
        return DisputeOutcome::from((string) $this->validated('outcome'));
    }

    /** @return array{party: string, amount: string, reason: CompensationReason, note: string}|null */
    public function compensation(): ?array
    {
        $c = $this->validated('compensation');

        return $c === null ? null : [
            'party' => (string) $c['party'],
            'amount' => (string) $c['amount'],
            'reason' => CompensationReason::from((string) $c['reason']),
            'note' => trim((string) $c['note']),
        ];
    }

    /** @return array{reason: SuspendedReason, note: ?string}|null */
    public function suspendSeller(): ?array
    {
        $s = $this->validated('suspend_seller');

        return $s === null ? null : ['reason' => SuspendedReason::from((string) $s['reason']), 'note' => $s['note'] ?? null];
    }
}
