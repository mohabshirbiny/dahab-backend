<?php

use App\Support\WorkingHours\WorkingCalendar;
use App\Support\WorkingHours\WorkingHoursUnavailable;
use Carbon\CarbonImmutable;

// Spec 004 SC-002 / Part 3 §1: hand-computed working-hours deadlines.
// 2026-10-01 is a Thursday. Weekly template unless stated: Sun–Thu 10:00–18:00.

function cairoWeek(array $closed = [], ?array $week = null): WorkingCalendar
{
    $week ??= array_fill_keys([0, 1, 2, 3, 4], [['10:00', '18:00']]);

    return new WorkingCalendar('Africa/Cairo', $week, $closed);
}

function cairo(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Africa/Cairo');
}

function local(CarbonImmutable $instant, string $tz = 'Africa/Cairo'): string
{
    return $instant->setTimezone($tz)->format('Y-m-d H:i');
}

it('counts Thursday 16:00 + 12 working hours to Monday 12:00 (weekend skipped)', function () {
    // 2h Thursday + 8h Sunday = 10h; the last 2h land on Monday morning.
    expect(local(cairoWeek()->addWorkingMinutes(cairo('2026-10-01 16:00'), 12 * 60)))->toBe('2026-10-05 12:00');
});

it('starts the clock at the next opening when the start falls on a closed day', function () {
    expect(local(cairoWeek()->addWorkingMinutes(cairo('2026-10-02 09:00'), 120)))->toBe('2026-10-04 12:00');
});

it('starts the clock at opening time when the start is before opening', function () {
    expect(local(cairoWeek()->addWorkingMinutes(cairo('2026-10-04 08:00'), 60)))->toBe('2026-10-04 11:00');
});

it('treats a start exactly at closing as after hours', function () {
    expect(local(cairoWeek()->addWorkingMinutes(cairo('2026-10-01 18:00'), 60)))->toBe('2026-10-04 11:00');
});

it('ends exactly at closing time when the last minute is the last open minute', function () {
    expect(local(cairoWeek()->addWorkingMinutes(cairo('2026-10-01 17:00'), 60)))->toBe('2026-10-01 18:00');
});

it('skips the lunch gap on a split day', function () {
    $week = [0 => [['10:00', '13:00'], ['14:00', '18:00']]];

    expect(local(cairoWeek(week: $week)->addWorkingMinutes(cairo('2026-10-04 12:00'), 120)))->toBe('2026-10-04 15:00');
});

it('gives zero time on a holiday that falls on an open day', function () {
    // Thursday 2h, Sunday closed, Monday 10:00 + 2h.
    expect(local(cairoWeek(['2026-10-04'])->addWorkingMinutes(cairo('2026-10-01 16:00'), 240)))->toBe('2026-10-05 12:00');
});

it('ignores a holiday on a day that is closed anyway', function () {
    expect(local(cairoWeek(['2026-10-02'])->addWorkingMinutes(cairo('2026-10-01 16:00'), 240)))->toBe('2026-10-04 12:00');
});

it('supports fractional hours to the minute', function () {
    expect(local(cairoWeek()->addWorkingMinutes(cairo('2026-10-04 10:00'), 90)))->toBe('2026-10-04 11:30');
});

it('spans several working days', function () {
    // Sunday 8h + Monday 8h + Tuesday 4h.
    expect(local(cairoWeek()->addWorkingMinutes(cairo('2026-10-04 10:00'), 20 * 60)))->toBe('2026-10-06 14:00');
});

it('counts in the branch timezone, not the server\'s or the customer\'s', function () {
    $dubai = new WorkingCalendar('Asia/Dubai', array_fill_keys([0, 1, 2, 3, 4], [['10:00', '18:00']]), []);
    // 05:00 UTC Sunday is 09:00 in Dubai: the clock starts at 10:00 Dubai.
    $deadline = $dubai->addWorkingMinutes(CarbonImmutable::parse('2026-10-04 05:00', 'UTC'), 60);

    expect(local($deadline, 'Asia/Dubai'))->toBe('2026-10-04 11:00')
        ->and($deadline->getTimestamp())->toBe(CarbonImmutable::parse('2026-10-04 07:00', 'UTC')->getTimestamp());
});

it('keeps wall-clock hours across the end of Egypt\'s summer time', function () {
    // Summer time ends on the last Thursday of October 2026 (29 Oct).
    expect(local(cairoWeek()->addWorkingMinutes(cairo('2026-10-28 17:00'), 120)))->toBe('2026-10-29 11:00');
});

it('returns the start unchanged for zero minutes', function () {
    expect(local(cairoWeek()->addWorkingMinutes(cairo('2026-10-02 09:00'), 0)))->toBe('2026-10-02 09:00');
});

it('refuses instead of looping when the branch is never open', function () {
    (new WorkingCalendar('Africa/Cairo', [], []))->addWorkingMinutes(cairo('2026-10-01 10:00'), 60);
})->throws(WorkingHoursUnavailable::class);
