<?php

namespace App\Http\Requests\Dashboard\TopUp;

use App\Enums\TopUpStatus;
use App\Support\TopUpCursor;
use App\Support\TopUpListQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Incoming transfers filters (spec 009 FR-015): `status` is a comma list
 * (default pending,on_hold); `from`/`to` are Cairo dates (default the last
 * 30 days); `q` searches reference, phone or name.
 */
class ListTopUpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge(['status' => array_values(array_filter(array_map('trim', explode(',', $this->input('status')))))]);
        }
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'array', 'min:1'],
            'status.*' => [Rule::enum(TopUpStatus::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['sometimes', 'nullable', 'string', 'max:64'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    public function listQuery(): TopUpListQuery
    {
        $today = Carbon::now((string) config('app.timezone'))->toDateString();
        $from = (string) $this->validated('from', Carbon::parse($today)->subDays(29)->toDateString());
        $to = (string) $this->validated('to', max($today, $from));

        return new TopUpListQuery(
            array_map(fn (string $s) => TopUpStatus::from($s), $this->validated('status', [TopUpStatus::PENDING->value, TopUpStatus::ON_HOLD->value])),
            $from,
            $to,
            $this->validated('q'),
        );
    }

    public function cursor(): ?TopUpCursor
    {
        return TopUpCursor::decode($this->validated('cursor'));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }
}
