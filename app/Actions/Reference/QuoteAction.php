<?php

namespace App\Actions\Reference;

use App\Enums\PieceCategory;
use App\Exceptions\DomainApiException;
use App\Models\Karat;
use App\Support\Pricing\Money;
use App\Support\Pricing\Piece;
use App\Support\Pricing\PricingContext;
use Illuminate\Validation\ValidationException;

/**
 * "What will I get" — the seller's indicative estimate for a piece at the
 * current gold price (spec 015 FR-021, research R13), from the spec 005
 * calculator only: gold value, the making charge back, commission, VAT and
 * the payout. Nothing is locked; a buy request locks a price, never a quote.
 */
final class QuoteAction
{
    public function __construct(private readonly PricingContext $pricing) {}

    /** @return array<string, mixed> */
    public function handle(PieceCategory $category, ?int $karatCode, ?string $weight, ?string $makingPerGram, ?string $askingPrice): array
    {
        $price = $this->pricing->currentPrice();
        if ($category !== PieceCategory::DIAMOND && $price === null) {
            throw DomainApiException::priceUnavailable();
        }

        $karat = null;
        if ($category !== PieceCategory::DIAMOND) {
            $enabled = Karat::query()->whereKey($karatCode)->where('is_enabled', true)->exists();
            if (! $enabled) {
                throw ValidationException::withMessages(['karat' => ['This karat is not available.']]);
            }
            $karat = $this->pricing->pricing((int) $karatCode);
        }

        $piece = match ($category) {
            PieceCategory::GOLD => Piece::gold($karat, (string) $weight, $makingPerGram ?? '0'),
            PieceCategory::GOLD_WITH_DIAMOND => Piece::goldWithDiamond($karat, (string) $weight, (string) $askingPrice),
            PieceCategory::DIAMOND => Piece::diamond((string) $askingPrice),
        };

        try {
            $b = $this->pricing->breakdown($piece);
        } catch (DomainApiException) {
            throw DomainApiException::priceUnavailable();
        }

        $rates = $this->pricing->rates();
        $making = $category === PieceCategory::GOLD ? Money::round4(Money::mul($makingPerGram ?? '0', (string) $weight)) : '0.0000';
        $goldValue = match ($category) {
            PieceCategory::GOLD => Money::fixed4(Money::sub($b->sellerGross, $making)),
            PieceCategory::GOLD_WITH_DIAMOND => (string) $b->protectedGoldValue,
            PieceCategory::DIAMOND => '0.0000',
        };
        $pct = $category === PieceCategory::GOLD ? $rates->goldPct : $rates->stonePct;
        $base = match ($category) {
            PieceCategory::GOLD => $making,
            PieceCategory::GOLD_WITH_DIAMOND => Money::fixed4(Money::max(Money::sub((string) $askingPrice, $goldValue), '0')),
            PieceCategory::DIAMOND => Money::fixed4((string) $askingPrice),
        };

        return [
            'category' => $category->value,
            'rate_per_gram' => $b->karatPrices?->sellersGet,
            'gold_value' => $goldValue,
            'making_back' => $making,
            'asking_price' => $askingPrice === null ? null : Money::fixed4($askingPrice),
            'commission' => $b->commission,
            'vat' => $b->vat,
            'payout' => $b->sellerProceeds,
            'commission_rate' => Money::fixed4($pct),
            'minimum_applied' => bccomp($b->commission, Money::round4(Money::percent($base, $pct)), 4) > 0,
            'indicative' => true,
            'price_at' => $price?->effective_at->toIso8601String(),
        ];
    }
}
