<?php

namespace App\Http\Requests\Dashboard\Order;

use App\Enums\DeadlineKind;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/** Extend a running order deadline on request (spec 012 FR-009). */
#[OA\Schema(
    schema: 'ExtendDeadlineRequest',
    required: ['which', 'new_deadline', 'reason'],
    properties: [
        new OA\Property(property: 'which', type: 'string', enum: ['reach_branch', 'balance', 'collect']),
        new OA\Property(property: 'new_deadline', type: 'string', format: 'date-time', description: 'Later than the current deadline and in the future'),
        new OA\Property(property: 'reason', type: 'string', minLength: 10, maxLength: 1000),
    ],
)]
class ExtendDeadlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'which' => ['required', 'in:reach_branch,balance,collect'],
            'new_deadline' => ['required', 'date'],
            'reason' => ['required', 'string', 'between:10,1000'],
        ];
    }

    public function which(): DeadlineKind
    {
        return DeadlineKind::from((string) $this->validated('which'));
    }

    public function newDeadline(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('new_deadline'));
    }
}
