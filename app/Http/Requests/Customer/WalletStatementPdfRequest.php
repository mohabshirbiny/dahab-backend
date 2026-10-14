<?php

namespace App\Http\Requests\Customer;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * The period of the customer's wallet statement PDF (wallet statement PDF, item 10): Cairo
 * calendar days, both inclusive. Default: the last 90 days ending today. At most 366 days, and
 * never past today. There is no customer parameter: the statement is always the caller's own.
 */
class WalletStatementPdfRequest extends FormRequest
{
    public const DEFAULT_DAYS = 90;

    public const MAX_DAYS = 366;

    private const TZ = 'Africa/Cairo';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $today = Carbon::now(self::TZ)->format('Y-m-d');

        return [
            'from' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'to' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.$today, function (string $attribute, mixed $value, Closure $fail) {
                $from = $this->input('from');
                if (! is_string($from) || ! is_string($value) || strtotime($from) === false || strtotime($value) === false) {
                    return;
                }
                if ($value < $from) {
                    $fail('The end of the period must be on or after its start.');
                } elseif (Carbon::parse($from)->diffInDays(Carbon::parse($value)) >= self::MAX_DAYS) {
                    $fail('A statement covers at most '.self::MAX_DAYS.' days.');
                }
            }],
        ];
    }

    /** @return array{0: string, 1: string} from, to */
    public function period(): array
    {
        $to = $this->validated('to') ?? Carbon::now(self::TZ)->format('Y-m-d');
        $from = $this->validated('from') ?? Carbon::parse($to, self::TZ)->subDays(self::DEFAULT_DAYS - 1)->format('Y-m-d');

        // Only `from` given and more than a year back: the year that starts there.
        if ($this->validated('to') === null && Carbon::parse($from)->diffInDays(Carbon::parse($to)) >= self::MAX_DAYS) {
            $to = Carbon::parse($from, self::TZ)->addDays(self::MAX_DAYS - 1)->format('Y-m-d');
        }

        return [$from, $to];
    }
}
