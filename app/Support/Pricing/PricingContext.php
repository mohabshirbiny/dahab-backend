<?php

namespace App\Support\Pricing;

use App\Enums\AdjustmentKind;
use App\Enums\AdjustmentSide;
use App\Enums\SettingKey;
use App\Models\GoldPrice;
use App\Models\Karat;
use App\Models\KaratPriceAdjustment;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Loads what the pure PriceCalculator needs — the current gold price, every
 * karat's purity and adjustments, and the commission/VAT settings — so every
 * caller prices the same way (spec 005 FR-011, FR-020).
 */
final class PricingContext
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PriceCalculator $calculator,
    ) {}

    public function currentPrice(): ?GoldPrice
    {
        return GoldPrice::current();
    }

    public function market(?GoldPrice $price = null): ?MarketPrice
    {
        $price ??= $this->currentPrice();

        return $price === null ? null : new MarketPrice((string) $price->bid_24k, (string) $price->ask_24k);
    }

    public function rates(): PricingRates
    {
        return new PricingRates(
            goldPct: $this->settings->numeric(SettingKey::COMMISSION_GOLD_PCT),
            stonePct: $this->settings->numeric(SettingKey::COMMISSION_STONE_PCT),
            minimumEgp: $this->settings->numeric(SettingKey::COMMISSION_MINIMUM_EGP),
            vatPct: $this->settings->numeric(SettingKey::VAT_PCT),
        );
    }

    /**
     * Every karat (enabled or not) in display order with its pricing inputs.
     *
     * @param  array<int, array{buy?: Adjustment, sell?: Adjustment}>  $overrides  per karat code, for previews
     * @return Collection<int, array{karat: Karat, pricing: KaratPricing}>
     */
    public function karats(array $overrides = []): Collection
    {
        return Karat::query()->ordered()->with('adjustments')->get()
            ->map(fn (Karat $karat) => [
                'karat' => $karat,
                'pricing' => $this->pricingFor($karat, $overrides[$karat->karat_code] ?? []),
            ]);
    }

    /**
     * Each karat's market and published prices at a market price.
     *
     * @param  array<int, array{buy?: Adjustment, sell?: Adjustment}>  $overrides
     * @return Collection<int, array{karat: Karat, pricing: KaratPricing, prices: KaratPrices}>
     */
    public function karatPrices(MarketPrice $market, array $overrides = []): Collection
    {
        return $this->karats($overrides)->map(fn (array $row) => $row + [
            'prices' => $this->calculator->karatPrices($market, $row['pricing']),
        ]);
    }

    public function breakdown(Piece $piece): PriceBreakdown
    {
        return $this->calculator->breakdown($this->market(), $piece, $this->rates());
    }

    public function pricing(int $karatCode): KaratPricing
    {
        $karat = Karat::query()->with('adjustments')->findOrFail($karatCode);

        return $this->pricingFor($karat);
    }

    /** @param  array{buy?: Adjustment, sell?: Adjustment}  $override */
    private function pricingFor(Karat $karat, array $override = []): KaratPricing
    {
        $side = function (AdjustmentSide $side) use ($karat): Adjustment {
            /** @var KaratPriceAdjustment|null $row */
            $row = $karat->adjustments->firstWhere('side', $side);

            return $row === null
                ? throw new LogicException("Karat {$karat->karat_code} has no {$side->value}-side price adjustment.")
                : new Adjustment($row->kind ?? AdjustmentKind::FIXED, (string) $row->value);
        };

        return new KaratPricing(
            code: $karat->karat_code,
            purity: (string) $karat->purity_ratio,
            buy: $override['buy'] ?? $side(AdjustmentSide::BUY),
            sell: $override['sell'] ?? $side(AdjustmentSide::SELL),
        );
    }
}
