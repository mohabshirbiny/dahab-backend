<?php

namespace App\Support\Disputes;

use App\Enums\SettingKey;
use App\Enums\StaffPermission;
use App\Models\Staff;
use App\Support\Pricing\Money;
use App\Support\Pricing\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The compensation caps of a staff member (Part 1 §4.2, Part 2 §9; spec 014
 * FR-015, research R8): `compensation.cap_per_payment_egp` per payment and
 * `compensation.cap_per_day_egp` per Cairo day (on the application clock, as
 * `paid_at`), read live from settings. `compensation.uncapped` lifts both.
 */
final class CompensationCaps
{
    public function __construct(private readonly Settings $settings) {}

    public function uncapped(Staff $staff): bool
    {
        return $staff->can(StaffPermission::COMPENSATION_UNCAPPED->value);
    }

    public function perPayment(): string
    {
        return Money::fixed4($this->settings->numeric(SettingKey::COMPENSATION_CAP_PER_PAYMENT_EGP));
    }

    public function perDay(): string
    {
        return Money::fixed4($this->settings->numeric(SettingKey::COMPENSATION_CAP_PER_DAY_EGP));
    }

    /** What this staff member paid since the start of today (Cairo). */
    public function paidToday(string $staffId): string
    {
        $dayStart = CarbonImmutable::now('Africa/Cairo')->startOfDay();

        return Money::fixed4((string) (DB::table('compensation')->where('paid_by', $staffId)
            ->where('paid_at', '>=', $dayStart)
            ->selectRaw('COALESCE(SUM(amount), 0)::text AS total')->value('total') ?? '0'));
    }

    /** What is left today; never below zero. */
    public function leftToday(string $staffId): string
    {
        return Money::fixed4(Money::max('0', Money::sub($this->perDay(), $this->paidToday($staffId))));
    }

    /** @return array{per_payment: string, left_today: string}|null null when uncapped */
    public function forStaff(Staff $staff): ?array
    {
        return $this->uncapped($staff) ? null : ['per_payment' => $this->perPayment(), 'left_today' => $this->leftToday($staff->staff_id)];
    }
}
