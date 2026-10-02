<?php

namespace App\Http\Requests\Dashboard\Customers;

use App\Enums\SuspendedReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/** Suspend a customer (spec 007 FR-004/FR-005). Strings arrive trimmed. */
#[OA\Schema(
    schema: 'DashboardSuspendCustomer',
    required: ['reason', 'note'],
    properties: [
        new OA\Property(property: 'reason', type: 'string', enum: ['piece_misrepresented', 'off_platform_dealing', 'repeated_disputes', 'reported_by_users', 'identity_unconfirmed', 'customer_request', 'other']),
        new OA\Property(property: 'note', type: 'string', minLength: 1, maxLength: 1000, description: 'For the record; staff-only, never shown to the customer'),
    ],
)]
class SuspendCustomerRequest extends FormRequest
{
    public const NOTE_MAX = 1000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Spec 012: repeated_cancellations is the system's own reason, never a staff choice.
            'reason' => ['required', Rule::enum(SuspendedReason::class)->except(SuspendedReason::REPEATED_CANCELLATIONS)],
            'note' => ['required', 'string', 'min:1', 'max:'.self::NOTE_MAX],
        ];
    }

    public function reason(): SuspendedReason
    {
        return SuspendedReason::from($this->validated('reason'));
    }

    public function note(): string
    {
        return (string) $this->validated('note');
    }
}
