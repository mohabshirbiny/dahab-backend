<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * A row the database never lets change (spec 008 FR-008). Failing here
 * gives a clear error instead of the silent 0-row UPDATE that row-level
 * security turns an edit into.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' is append-only; post a reversal instead.'));
        static::deleting(fn () => throw new LogicException(static::class.' is append-only; post a reversal instead.'));
    }
}
