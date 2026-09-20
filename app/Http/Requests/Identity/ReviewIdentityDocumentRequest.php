<?php

namespace App\Http\Requests\Identity;

use App\Enums\IdentityDocumentStatus;
use App\Enums\IdentityReviewReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * The Dashboard's Verify / Ask again / Reject payload.
 *
 * `action` chooses the outcome; `reasons[]` and `note` carry the structured
 * feedback the reviewer selected in the UI. Reasons are optional for
 * `verify`, required for `request_resubmission` and `reject`.
 */
#[OA\Schema(
    schema: 'ReviewIdentityDocumentRequest',
    required: ['action'],
    properties: [
        new OA\Property(property: 'action', type: 'string', enum: ['verify', 'request_resubmission', 'reject']),
        new OA\Property(property: 'reasons', type: 'array', items: new OA\Items(type: 'string', enum: ['blurred_or_glare', 'card_cut_off', 'name_does_not_match', 'card_expired', 'back_missing', 'text_not_readable']), nullable: true),
        new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
    ],
)]
class ReviewIdentityDocumentRequest extends FormRequest
{
    public const ACTION_VERIFY = 'verify';

    public const ACTION_REQUEST_RESUBMISSION = 'request_resubmission';

    public const ACTION_REJECT = 'reject';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in([self::ACTION_VERIFY, self::ACTION_REQUEST_RESUBMISSION, self::ACTION_REJECT])],
            'reasons' => [
                Rule::requiredIf(fn () => in_array($this->input('action'), [self::ACTION_REQUEST_RESUBMISSION, self::ACTION_REJECT], true)),
                'array',
                'min:1',
            ],
            'reasons.*' => [Rule::enum(IdentityReviewReason::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function decision(): IdentityDocumentStatus
    {
        return match ($this->validated('action')) {
            self::ACTION_VERIFY => IdentityDocumentStatus::VERIFIED,
            self::ACTION_REQUEST_RESUBMISSION => IdentityDocumentStatus::NEEDS_RESUBMISSION,
            self::ACTION_REJECT => IdentityDocumentStatus::REJECTED,
        };
    }

    /** @return list<IdentityReviewReason> */
    public function reasons(): array
    {
        return array_map(
            fn (string $r) => IdentityReviewReason::from($r),
            (array) $this->validated('reasons', []),
        );
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
