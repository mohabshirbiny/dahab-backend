<?php

namespace App\Support\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

/**
 * A Cairo date range on the finance lists (spec 015): `from` and `to`
 * inclusive, at most 366 days apart, defaulting to the last N days.
 */
final class Period
{
    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public static function after(Request $request): \Closure
    {
        return function (Validator $v) use ($request) {
            if ($v->errors()->hasAny(['from', 'to'])) {
                return;
            }
            [$from, $to] = self::of($request, 30);
            if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) {
                $v->errors()->add('to', 'The period can be at most 366 days.');
            }
        };
    }

    /** @return array{0: string, 1: string} from, to (Cairo dates) */
    public static function of(Request $request, int $defaultDays): array
    {
        $today = CarbonImmutable::now('Africa/Cairo')->toDateString();
        $to = (string) ($request->input('to') ?: $today);
        $from = (string) ($request->input('from') ?: CarbonImmutable::parse($to)->subDays($defaultDays - 1)->toDateString());

        return [$from, $to];
    }
}
