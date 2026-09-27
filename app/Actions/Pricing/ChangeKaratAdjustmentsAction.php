<?php

namespace App\Actions\Pricing;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Pricing\Concerns\LocksGoldPrice;
use App\Enums\AdjustmentKind;
use App\Enums\AdjustmentSide;
use App\Enums\AuditEvent;
use App\Exceptions\DomainApiException;
use App\Models\Karat;
use App\Models\KaratPriceAdjustment;
use App\Models\KaratPriceAdjustmentHistory;
use App\Models\Staff;
use App\Support\Authorization\ReasonRule;
use App\Support\Pricing\Adjustment;
use App\Support\Pricing\KaratPricing;
use App\Support\Pricing\PriceCalculator;
use App\Support\Pricing\PricingContext;

/**
 * Replace a karat's buy-side and sell-side adjustments (spec 005 FR-017).
 * Refused when, at the current price, the karat's published prices would
 * be inverted. Each changed side is kept in history and audited.
 */
final class ChangeKaratAdjustmentsAction
{
    use LocksGoldPrice;

    public function __construct(
        private readonly PricingContext $context,
        private readonly PriceCalculator $calculator,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, int $karatCode, Adjustment $buy, Adjustment $sell, ?string $reason): KaratPricing
    {
        ReasonRule::assertPresent($reason);

        return $this->withPriceLock(function () use ($actor, $karatCode, $buy, $sell, $reason) {
            $karat = Karat::query()->findOrFail($karatCode);
            $pricing = new KaratPricing($karat->karat_code, (string) $karat->purity_ratio, $buy, $sell);

            $market = $this->context->market();
            if ($market !== null && $this->calculator->karatPrices($market, $pricing)->inverted) {
                throw DomainApiException::priceInverted($karat->karat_code);
            }

            foreach ([AdjustmentSide::BUY->value => $buy, AdjustmentSide::SELL->value => $sell] as $side => $new) {
                $this->apply($actor, $karat->karat_code, AdjustmentSide::from($side), $new, trim($reason));
            }

            return $this->context->pricing($karat->karat_code);
        });
    }

    private function apply(Staff $actor, int $karatCode, AdjustmentSide $side, Adjustment $new, string $reason): void
    {
        $row = KaratPriceAdjustment::query()->where('karat_code', $karatCode)->where('side', $side)->lockForUpdate()->first();
        $oldKind = $row?->kind ?? AdjustmentKind::FIXED;
        $oldValue = $row === null ? '0.0000' : (string) $row->value;

        if ($oldKind === $new->kind && bccomp($oldValue, $new->value, 4) === 0) {
            return;
        }

        KaratPriceAdjustment::query()->updateOrInsert(
            ['karat_code' => $karatCode, 'side' => $side->value],
            ['kind' => $new->kind->value, 'value' => $new->value, 'updated_by' => $actor->staff_id, 'updated_at' => now()],
        );

        KaratPriceAdjustmentHistory::query()->create([
            'karat_code' => $karatCode,
            'side' => $side,
            'old_kind' => $oldKind,
            'old_value' => $oldValue,
            'new_kind' => $new->kind,
            'new_value' => $new->value,
            'changed_by' => $actor->staff_id,
            'reason' => $reason,
        ]);

        $this->audit->execute(
            AuditEvent::ADJUSTMENT_CHANGED,
            'success',
            ['karat_code' => $karatCode, 'side' => $side->value, 'new' => ['kind' => $new->kind->value, 'value' => $new->value]],
            entityType: 'karat_price_adjustment',
            actorStaffId: $actor->staff_id,
            before: ['kind' => $oldKind->value, 'value' => $oldValue],
            reason: $reason,
        );
    }
}
