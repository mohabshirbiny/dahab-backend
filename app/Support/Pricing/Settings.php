<?php

namespace App\Support\Pricing;

use App\Enums\SettingKey;
use App\Models\Setting;
use LogicException;

/**
 * The one way code reads a tunable number (spec 005 FR-005). Values are read
 * fresh each call, so a change applies to the very next calculation (SC-007).
 * Numbers come back as bcmath strings, never floats.
 */
final class Settings
{
    public function numeric(SettingKey $key): string
    {
        if ($key->isBool()) {
            throw new LogicException("Setting [{$key->value}] is a flag, not a number.");
        }

        $value = $this->row($key)->value_numeric;

        return $value === null ? throw new LogicException("Setting [{$key->value}] has no value.") : (string) $value;
    }

    public function integer(SettingKey $key): int
    {
        return (int) $this->numeric($key);
    }

    public function bool(SettingKey $key): bool
    {
        if (! $key->isBool()) {
            throw new LogicException("Setting [{$key->value}] is a number, not a flag.");
        }

        return (bool) $this->row($key)->value_bool;
    }

    private function row(SettingKey $key): Setting
    {
        return Setting::query()->find($key->value)
            ?? throw new LogicException("Setting [{$key->value}] is missing; run the migrations.");
    }
}
