<?php

namespace App\Actions\Pricing;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Pricing\Concerns\LocksGoldPrice;
use App\Enums\AuditEvent;
use App\Enums\ManualPriceStatus;
use App\Enums\PriceSource;
use App\Enums\SettingKey;
use App\Exceptions\DomainApiException;
use App\Models\GoldPrice;
use App\Models\ManualGoldPrice;
use App\Models\Staff;
use App\Support\Pricing\Settings;

/**
 * Confirm a pending manual price (spec 005 FR-013, FR-014). Only the latest
 * pending request, within its window, and only if no other price took effect
 * since it was entered. manualprice.confirmer_must_differ decides whether
 * the person who entered it may confirm it.
 */
final class ConfirmManualPriceAction
{
    use LocksGoldPrice;

    public function __construct(
        private readonly Settings $settings,
        private readonly RecordAuditLogAction $audit,
    ) {}

    public function handle(Staff $actor, int $manualGoldPriceId): ManualGoldPrice
    {
        return $this->withPriceLock(function () use ($actor, $manualGoldPriceId) {
            $manual = ManualGoldPrice::query()->lockForUpdate()->findOrFail($manualGoldPriceId);

            $overtaken = GoldPrice::current()?->gold_price_id !== $manual->previous_gold_price_id;
            if (! $manual->isConfirmable() || $overtaken) {
                throw DomainApiException::manualPriceNotPending();
            }

            if ($manual->entered_by === $actor->staff_id && $this->settings->bool(SettingKey::MANUALPRICE_CONFIRMER_MUST_DIFFER)) {
                throw DomainApiException::confirmerMustDiffer();
            }

            $manual->update([
                'status' => ManualPriceStatus::EFFECTIVE,
                'confirmed_by' => $actor->staff_id,
                'confirmed_at' => now(),
            ]);

            $price = GoldPrice::query()->create([
                'source' => PriceSource::MANUAL,
                'bid_24k' => (string) $manual->bid_24k,
                'ask_24k' => (string) $manual->ask_24k,
                'manual_gold_price_id' => $manual->manual_gold_price_id,
                'recorded_by' => $actor->staff_id,
            ]);

            $this->audit->execute(
                AuditEvent::MANUAL_PRICE_CONFIRMED,
                'success',
                ['manual_gold_price_id' => $manual->manual_gold_price_id, 'gold_price_id' => $price->gold_price_id],
                entityType: 'gold_price',
                actorStaffId: $actor->staff_id,
            );

            return $manual->refresh();
        });
    }
}
