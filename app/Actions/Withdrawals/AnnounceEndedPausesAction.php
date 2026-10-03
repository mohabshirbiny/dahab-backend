<?php

namespace App\Actions\Withdrawals;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\PayoutEvent;
use App\Jobs\NotifyCustomerJob;
use App\Models\WithdrawalPause;
use App\Notifications\PayoutNotification;
use App\Support\SystemActor;
use Illuminate\Support\Facades\DB;

/**
 * The withdrawal-pause expiry job (Part 2 §11, Part 3 §12 — changed by spec
 * 013: un-left withdrawals were cancelled at the change, so nothing moves to
 * review; the job tells the customer withdrawals are open again). One pause
 * per transaction, re-checked under its lock, so a second run changes
 * nothing. A pause superseded by a later open pause is stamped silently.
 */
final class AnnounceEndedPausesAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @return list<string> pause ids due now */
    public function due(): array
    {
        return WithdrawalPause::query()->whereNull('ended_notified_at')->where('pause_until', '<=', now())
            ->orderBy('pause_until')->limit((int) config('dahab-withdrawals.sweep_batch'))
            ->pluck('pause_id')->all();
    }

    /** True when the customer was told. */
    public function handle(string $pauseId): bool
    {
        return DB::transaction(function () use ($pauseId) {
            $pause = WithdrawalPause::query()->whereKey($pauseId)->lockForUpdate()->first();
            if ($pause === null || $pause->ended_notified_at !== null || $pause->pause_until->isFuture()) {
                return false;
            }

            $superseded = WithdrawalPause::query()->where('customer_id', $pause->customer_id)
                ->whereKeyNot($pause->pause_id)->where('pause_until', '>', now())->exists();

            $pause->forceFill(['ended_notified_at' => now()])->save();

            $this->audit->execute(AuditEvent::WITHDRAWAL_PAUSE_ENDED, 'success',
                ['pause_id' => $pause->pause_id, 'pause_until' => $pause->pause_until->toIso8601String(), 'superseded' => $superseded],
                'withdrawal_pause', $pause->pause_id, actorStaffId: SystemActor::id());

            if (! $superseded) {
                $customerId = $pause->customer_id;
                DB::afterCommit(fn () => NotifyCustomerJob::dispatch($customerId, new PayoutNotification(PayoutEvent::WITHDRAWALS_OPEN)));
            }

            return ! $superseded;
        });
    }
}
