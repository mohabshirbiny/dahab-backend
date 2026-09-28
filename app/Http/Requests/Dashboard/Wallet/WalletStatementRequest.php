<?php

namespace App\Http\Requests\Dashboard\Wallet;

use App\Support\Ledger\StatementCursor;
use App\Support\Ledger\StatementQuery;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** A Wallet statement query (spec 008 FR-016, contracts/wallet-api.md). */
class WalletStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'view' => ['required', Rule::in(StatementQuery::VIEWS)],
            'customer_id' => ['required_if:view,customer', 'prohibited_unless:view,customer', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', function (string $attribute, mixed $value, Closure $fail) {
                $from = $this->input('from');
                if (is_string($from) && is_string($value) && strtotime($from) !== false && strtotime($value) !== false
                    && Carbon::parse($from)->diffInDays(Carbon::parse($value)) > 366) {
                    $fail('A statement covers at most 366 days.');
                }
            }],
            'grain' => ['sometimes', Rule::in(StatementQuery::GRAINS)],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
        ];
    }

    public function statementQuery(): StatementQuery
    {
        return new StatementQuery(
            $this->validated('view'),
            $this->validated('from'),
            $this->validated('to'),
            $this->validated('grain', 'each'),
            $this->validated('customer_id'),
        );
    }

    public function cursor(): ?StatementCursor
    {
        return StatementCursor::decode($this->validated('cursor'), $this->validated('grain', 'each'));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 50);
    }
}
