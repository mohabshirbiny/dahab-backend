<?php

namespace App\Actions\Reference;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Karat;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * Turn a karat on or off (spec 004 FR-021, Part 2 §10). Takes effect at
 * once; audited when it actually changes.
 */
final class ToggleKaratAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $actor, int $code, bool $enabled): Karat
    {
        return DB::transaction(function () use ($actor, $code, $enabled) {
            $karat = Karat::query()->lockForUpdate()->findOrFail($code);

            if ($karat->is_enabled === $enabled) {
                return $karat;
            }

            $karat->is_enabled = $enabled;
            $karat->save();

            $this->audit->execute(
                AuditEvent::KARAT_TOGGLED,
                'success',
                ['karat_code' => $karat->karat_code, 'is_enabled' => $enabled],
                entityType: 'karat',
                actorStaffId: $actor->staff_id,
                before: ['is_enabled' => ! $enabled],
            );

            return $karat;
        });
    }
}
