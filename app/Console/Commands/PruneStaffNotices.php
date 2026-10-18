<?php

namespace App\Console\Commands;

use App\Enums\StaffNoticeKind;
use App\Models\StaffNotice;
use Illuminate\Console\Command;

/**
 * Spec 026 D26-5: read work notices older than `dahab-notices.work_read_retention_days` are
 * deleted. Unread notices and the founder security alerts of spec 021 are never touched.
 * Notices are messages, not audit (the audit log keeps what happened).
 */
class PruneStaffNotices extends Command
{
    protected $signature = 'staff-notices:prune';

    protected $description = 'Delete read Dashboard work notices past their retention';

    public function handle(): int
    {
        $days = max(1, (int) config('dahab-notices.work_read_retention_days', 30));

        $deleted = StaffNotice::query()
            ->whereIn('kind', array_map(fn (StaffNoticeKind $k) => $k->value, StaffNoticeKind::work()))
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$deleted} read work notices older than {$days} days.");

        return self::SUCCESS;
    }
}
