<?php

namespace App\Actions\Pricing\Concerns;

use App\Enums\ManualPriceStatus;
use App\Models\ManualGoldPrice;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Manual entries, confirmations and feed writes run one at a time, so the
 * "current price" a deviation is measured against is the one at write time
 * (research R2).
 */
trait LocksGoldPrice
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    protected function withPriceLock(Closure $work): mixed
    {
        return DB::transaction(function () use ($work) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT pg_advisory_xact_lock(hashtext('dahab.gold_price'))");
            }

            return $work();
        });
    }

    /** A new price took effect or a newer request arrived: the waiting one is done (lapsed if already expired). */
    protected function closePending(): void
    {
        $pending = ManualGoldPrice::query()->where('status', ManualPriceStatus::PENDING)->lockForUpdate()->first();

        if ($pending !== null) {
            $pending->update([
                'status' => $pending->isConfirmable() ? ManualPriceStatus::SUPERSEDED : ManualPriceStatus::LAPSED,
            ]);
        }
    }
}
