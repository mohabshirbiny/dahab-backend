<?php

namespace App\Support;

use App\Models\Staff;
use RuntimeException;

/**
 * The single "System" staff record that scheduled jobs attribute their
 * writes to (spec 002 FR-060–FR-062, Part 2 §11). Created by migration
 * `2026_09_26_000030_insert_system_actor`; it cannot sign in, holds no
 * roles, and never appears in staff management.
 */
final class SystemActor
{
    private static ?string $id = null;

    public static function id(): string
    {
        return self::$id ??= Staff::query()->where('is_system', true)->value('staff_id')
            ?? throw new RuntimeException('The system actor is missing; run the migrations.');
    }

    /** Tests that rebuild the database between cases must not reuse a stale id. */
    public static function forget(): void
    {
        self::$id = null;
    }
}
