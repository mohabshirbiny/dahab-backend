<?php

namespace App\Http\Requests\Dashboard\Dispute;

use App\Enums\DisputeState;
use App\Support\Listings\ListingCursor;
use Illuminate\Foundation\Http\FormRequest;

/** The Disputes queue (spec 014 FR-008, research R17). */
class ListDisputesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'string', 'in:unresolved,open,passed_on,resolved'],
            'assigned' => ['sometimes', 'string', 'in:me'],
            'q' => ['sometimes', 'nullable', 'string', 'max:40'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    /** @return list<DisputeState> */
    public function states(): array
    {
        $state = (string) $this->validated('state', 'unresolved');

        return $state === 'unresolved' ? [DisputeState::OPEN, DisputeState::PASSED_ON] : [DisputeState::from($state)];
    }

    public function assignedToMe(): bool
    {
        return $this->validated('assigned') === 'me';
    }

    public function cursor(): ?ListingCursor
    {
        return ListingCursor::decode($this->validated('cursor'));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }
}
