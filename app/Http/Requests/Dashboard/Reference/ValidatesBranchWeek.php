<?php

namespace App\Http\Requests\Dashboard\Reference;

use Illuminate\Validation\Validator;

/**
 * A week is a list of open intervals, several per day allowed (split days).
 * Each must close after it opens, and intervals on the same day must not
 * overlap or share a start (spec 004 FR-002). Errors are keyed `hours.N`.
 */
trait ValidatesBranchWeek
{
    /** @return array<string, list<mixed>> */
    protected function weekRules(bool $required): array
    {
        return [
            'hours' => [$required ? 'present' : 'sometimes', 'array', 'max:50'],
            'hours.*' => ['array:dow,opens_at,closes_at'],
            'hours.*.dow' => ['required', 'integer', 'between:0,6'],
            'hours.*.opens_at' => ['required', 'string', 'date_format:H:i'],
            'hours.*.closes_at' => ['required', 'string', 'date_format:H:i'],
        ];
    }

    /** @return list<callable> */
    protected function weekAfter(): array
    {
        return [function (Validator $validator) {
            $hours = $this->input('hours');
            if (! is_array($hours) || $validator->errors()->isNotEmpty()) {
                return;
            }

            $byDay = [];
            foreach (array_values($hours) as $i => $h) {
                if ($h['closes_at'] <= $h['opens_at']) {
                    $validator->errors()->add("hours.$i.closes_at", 'Closing time must be after opening time.');

                    continue;
                }
                $byDay[(int) $h['dow']][] = ['i' => $i] + $h;
            }

            foreach ($byDay as $intervals) {
                usort($intervals, fn ($a, $b) => $a['opens_at'] <=> $b['opens_at']);
                for ($k = 1; $k < count($intervals); $k++) {
                    if ($intervals[$k]['opens_at'] < $intervals[$k - 1]['closes_at']) {
                        $validator->errors()->add('hours.'.$intervals[$k]['i'], 'This interval overlaps another one on the same day.');
                    }
                }
            }
        }];
    }
}
