<?php

namespace App\Actions\Pricing;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\SettingHistory;
use App\Models\Staff;
use App\Support\Authorization\ReasonRule;
use App\Support\Pricing\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change one setting's value (spec 005 FR-002, FR-003). The caller has
 * already checked the key's group permission. Type and range come from the
 * catalogue; the change is kept in history with its reason and audited.
 */
final class ChangeSettingAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $actor, SettingKey $key, mixed $value, ?string $reason): Setting
    {
        ReasonRule::assertPresent($reason);
        $value = self::validated($key, $value);

        return DB::transaction(function () use ($actor, $key, $value, $reason) {
            $setting = Setting::query()->lockForUpdate()->findOrFail($key->value);
            $column = $key->isBool() ? 'value_bool' : 'value_numeric';
            $old = $setting->{$column};

            $setting->forceFill([$column => $value, 'updated_by' => $actor->staff_id, 'updated_at' => now()])->save();

            SettingHistory::query()->create([
                'setting_key' => $key->value,
                $key->isBool() ? 'old_bool' : 'old_numeric' => $old,
                $key->isBool() ? 'new_bool' : 'new_numeric' => $value,
                'changed_by' => $actor->staff_id,
                'reason' => trim($reason),
            ]);

            $this->audit->execute(
                AuditEvent::SETTING_CHANGED,
                'success',
                ['key' => $key->value, 'new' => $value],
                entityType: 'setting',
                actorStaffId: $actor->staff_id,
                before: ['key' => $key->value, 'old' => $old === null ? null : (is_bool($old) ? $old : (string) $old)],
                reason: trim($reason),
            );

            return $setting->refresh();
        });
    }

    /** @return bool|string the value in its stored form */
    private static function validated(SettingKey $key, mixed $value): bool|string
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['value' => [$message]]);

        if ($key->isBool()) {
            return is_bool($value) ? $value : $fail('The value must be true or false.');
        }

        if (is_bool($value) || ! is_numeric($value) || ! preg_match('/^-?\d+(\.\d{1,4})?$/', (string) $value)) {
            $fail('The value must be a number with at most 4 decimals.');
        }
        $value = (string) $value;

        if ($key->isInteger() && str_contains($value, '.') && Money::cmp($value, (string) (int) $value) !== 0) {
            $fail('The value must be a whole number.');
        }
        if (($min = $key->min()) !== null && Money::cmp($value, $min) < 0) {
            $fail("The value must be at least {$min}.");
        }
        if (($max = $key->max()) !== null && Money::cmp($value, $max) > 0) {
            $fail("The value must be at most {$max}.");
        }

        return $value;
    }
}
