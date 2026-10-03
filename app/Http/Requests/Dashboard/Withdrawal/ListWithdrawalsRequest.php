<?php

namespace App\Http\Requests\Dashboard\Withdrawal;

use App\Enums\WithdrawalState;
use App\Support\Listings\ListingCursor;
use App\Support\Withdrawals\WithdrawalListQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** The staff Withdrawals list and its export (spec 013 FR-012). */
class ListWithdrawalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'string', 'max:120'],
            'held' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'customer_id' => ['sometimes', 'uuid'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            foreach ($this->rawStates() as $state) {
                if (WithdrawalState::tryFrom($state) === null) {
                    $v->errors()->add('state', "Unknown state [{$state}].");
                }
            }
        }];
    }

    /** @return list<string> */
    private function rawStates(): array
    {
        $raw = (string) $this->input('state', '');

        return $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function listQuery(): WithdrawalListQuery
    {
        $states = array_map(fn (string $s) => WithdrawalState::from($s), $this->rawStates());

        return new WithdrawalListQuery(
            $states === [] ? [WithdrawalState::REQUESTED, WithdrawalState::UNDER_REVIEW] : $states,
            $this->boolean('held'),
            $this->validated('from'),
            $this->validated('to'),
            $this->validated('q'),
            $this->validated('customer_id'),
        );
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
