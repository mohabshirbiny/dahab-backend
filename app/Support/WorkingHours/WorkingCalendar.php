<?php

namespace App\Support\WorkingHours;

use Carbon\CarbonImmutable;

/**
 * A branch's working calendar, and the one calculation of working-hours
 * deadlines (Technical Spec Part 3 §1, spec 004). Pure: it works on data
 * already loaded (WorkingHoursResolver loads it), so its behaviour is fully
 * covered by hand-computed scenarios without a database.
 *
 * Working time accrues only inside the weekly open intervals, in the
 * branch's own timezone. A closed date contributes nothing. A start outside
 * open hours waits for the next opening. Only deadlines for an action a
 * person must take at a branch use this; storage windows are calendar time
 * (Part 3 §1.3).
 */
final class WorkingCalendar
{
    /** How far ahead to look for open time before giving up (FR-011). */
    public const MAX_DAYS = 366;

    /**
     * @param  array<int, list<array{0: string, 1: string}>>  $week  dow (0 = Sunday … 6 = Saturday) => [[opens "HH:MM", closes "HH:MM"], …]
     * @param  list<string>  $closedDates  local dates "Y-m-d" (branch closures and national holidays)
     */
    public function __construct(
        private readonly string $timezone,
        private readonly array $week,
        private readonly array $closedDates,
    ) {}

    public function addWorkingMinutes(CarbonImmutable $start, int $minutes): CarbonImmutable
    {
        if ($minutes <= 0) {
            return $start;
        }

        $remaining = $minutes * 60;
        $cursor = $start->setTimezone($this->timezone);
        $closed = array_flip($this->closedDates);

        for ($day = 0; $day <= self::MAX_DAYS; $day++) {
            $date = $cursor->startOfDay();

            if (! isset($closed[$date->toDateString()])) {
                foreach ($this->intervalsOn($date) as [$opens, $closes]) {
                    if ($closes->lessThanOrEqualTo($cursor)) {
                        continue;
                    }

                    $from = $opens->greaterThan($cursor) ? $opens : $cursor;
                    $available = $closes->getTimestamp() - $from->getTimestamp();

                    if ($remaining <= $available) {
                        return $from->addSeconds($remaining);
                    }

                    $remaining -= $available;
                    $cursor = $closes;
                }
            }

            $cursor = $date->addDay();
        }

        throw new WorkingHoursUnavailable("No open time within {$this->timezone} calendar's next ".self::MAX_DAYS.' days.');
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> the day's open intervals, earliest first */
    private function intervalsOn(CarbonImmutable $date): array
    {
        $intervals = [];

        foreach ($this->week[$date->dayOfWeek] ?? [] as [$opens, $closes]) {
            $intervals[] = [$this->at($date, $opens), $this->at($date, $closes)];
        }

        usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return $intervals;
    }

    private function at(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $date->setTime($hour, $minute);
    }
}
