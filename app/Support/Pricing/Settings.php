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

    /**
     * Several flags in one read, still fresh (the switches' visibility, polled by every app).
     *
     * @param  list<SettingKey>  $keys
     * @return array<string, bool> keyed by the setting key
     */
    public function bools(array $keys): array
    {
        foreach ($keys as $key) {
            if (! $key->isBool()) {
                throw new LogicException("Setting [{$key->value}] is a number, not a flag.");
            }
        }
        $rows = Setting::query()->whereIn('setting_key', array_map(fn (SettingKey $k) => $k->value, $keys))->get()->keyBy('setting_key');

        $out = [];
        foreach ($keys as $key) {
            $row = $rows->get($key->value) ?? throw new LogicException("Setting [{$key->value}] is missing; run the migrations.");
            $out[$key->value] = (bool) $row->value_bool;
        }

        return $out;
    }

    private function row(SettingKey $key): Setting
    {
        return Setting::query()->find($key->value)
            ?? throw new LogicException("Setting [{$key->value}] is missing; run the migrations.");
    }
}
