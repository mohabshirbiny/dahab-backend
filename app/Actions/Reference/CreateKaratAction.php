<?php

namespace App\Actions\Reference;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\Karat;
use App\Models\KaratPriceAdjustment;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * Add a karat (spec 004 FR-022). It starts off, so nothing is offered to
 * sellers before its price exists; code and purity never change afterwards.
 * Without a sort order it goes last.
 */
final class CreateKaratAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** @param  array{code: int, purity: string|float, sort_order?: int}  $data */
    public function handle(Staff $actor, array $data): Karat
    {
        return DB::transaction(function () use ($actor, $data) {
            $karat = Karat::query()->create([
                'karat_code' => (int) $data['code'],
                'purity_ratio' => (string) $data['purity'],
                'is_enabled' => false,
                'sort_order' => $data['sort_order'] ?? ((int) Karat::query()->max('sort_order')) + 1,
            ])->refresh();

            // Spec 005: a new karat starts with zero price adjustments; Finance sets them before turning it on.
            KaratPriceAdjustment::seedZero($karat->karat_code);

            $this->audit->execute(
                AuditEvent::KARAT_CREATED,
                'success',
                [
                    'karat_code' => $karat->karat_code,
                    'purity_ratio' => $karat->purity_ratio,
                    'is_enabled' => $karat->is_enabled,
                    'sort_order' => $karat->sort_order,
                ],
                entityType: 'karat',
                actorStaffId: $actor->staff_id,
            );

            return $karat;
        });
    }
}
