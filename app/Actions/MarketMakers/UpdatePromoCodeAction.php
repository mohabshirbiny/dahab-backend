<?php

namespace App\Actions\MarketMakers;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Models\PromoCode;
use App\Models\Staff;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Change a code's monthly cap or turn it on or off (spec 019 FR-060). Nothing else about a
 * code changes (guard DH020). Turning it off records who and when; turning it back on clears
 * that pair. Every change is audited with the values before.
 */
final class UpdatePromoCodeAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /**
     * @param  array{monthly_cap_egp?: ?string, active?: bool}  $changes
     */
    public function handle(Staff $actor, string $code, array $changes, ?RequestContext $ctx = null): PromoCode
    {
        return DB::transaction(function () use ($actor, $code, $changes, $ctx) {
            $promo = PromoCode::query()->whereKey($code)->lockForUpdate()->firstOrFail();
            $before = ['monthly_cap_egp' => $promo->monthly_cap_egp, 'is_active' => $promo->is_active];

            if (array_key_exists('monthly_cap_egp', $changes)) {
                $promo->monthly_cap_egp = $changes['monthly_cap_egp'];
            }
            if (array_key_exists('active', $changes) && $changes['active'] !== $promo->is_active) {
                $promo->is_active = $changes['active'];
                $promo->deactivated_by = $changes['active'] ? null : $actor->staff_id;
                $promo->deactivated_at = $changes['active'] ? null : now();
            }

            if ($promo->isDirty()) {
                $promo->save();

                $this->audit->execute(
                    AuditEvent::PROMO_UPDATED,
                    'success',
                    ['code' => $promo->code, 'monthly_cap_egp' => $promo->monthly_cap_egp, 'is_active' => $promo->is_active],
                    entityType: 'promo_code',
                    ctx: $ctx,
                    actorStaffId: $actor->staff_id,
                    before: $before,
                );
            }

            return $promo->refresh();
        });
    }
}
