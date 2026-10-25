<?php

namespace App\Actions\MarketMakers;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\PromoCode;
use App\Models\Staff;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;

/**
 * Delete a code nobody has used yet (owner request 2026-10-26) — a typo or a code made by mistake.
 * A used code stays for its history: `promo_code_in_use` (guard DH020); turn it off instead.
 * Audited with every term it had (promo.deleted).
 */
final class DeletePromoCodeAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    public function handle(Staff $actor, string $code, ?RequestContext $ctx = null): void
    {
        DB::transaction(function () use ($actor, $code, $ctx) {
            $promo = PromoCode::query()->whereKey(strtoupper($code))->lockForUpdate()->firstOrFail();
            if (! PromoCode::unused($promo->code)) {
                throw DomainApiException::promoCodeInUse();
            }

            $before = $promo->attributesToArray();
            $promo->delete();

            $this->audit->execute(AuditEvent::PROMO_DELETED, 'success', ['code' => $promo->code],
                entityType: 'promo_code', ctx: $ctx, actorStaffId: $actor->staff_id, before: $before);
        });
    }
}
