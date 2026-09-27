<?php

namespace App\Support\Pricing;

use App\Enums\PieceCategory;
use App\Exceptions\DomainApiException;

/**
 * The single implementation of Technical Spec Part 3 §2 as amended by spec
 * 005. Pure: it reads no clock and no database; PricingContext supplies the
 * current price, adjustments and rates.
 */
final class PriceCalculator
{
    /** 24K purity: a karat's price is the 24K price × purity ÷ this. */
    private const PURITY_24K = '0.999';

    public function karatPrices(MarketPrice $market, KaratPricing $karat): KaratPrices
    {
        $marketBid = Money::round4(Money::div(Money::mul($market->bid24k, $karat->purity), self::PURITY_24K));
        $marketAsk = Money::round4(Money::div(Money::mul($market->ask24k, $karat->purity), self::PURITY_24K));
        $mid = Money::round4(Money::div(Money::add($marketBid, $marketAsk), '2'));

        $sellersGet = Money::round4($karat->buy->apply($marketBid));
        $buyersPay = Money::round4($karat->sell->apply($marketAsk));

        return new KaratPrices(
            code: $karat->code,
            marketBid: $marketBid,
            marketAsk: $marketAsk,
            mid: $mid,
            sellersGet: $sellersGet,
            buyersPay: $buyersPay,
            difference: Money::fixed4(Money::sub($buyersPay, $sellersGet)),
            inverted: Money::cmp($sellersGet, '0') <= 0 || Money::cmp($buyersPay, $sellersGet) < 0,
        );
    }

    public function breakdown(?MarketPrice $market, Piece $piece, PricingRates $rates): PriceBreakdown
    {
        return match ($piece->category) {
            PieceCategory::GOLD => $this->gold($this->requireMarket($market), $piece, $rates),
            PieceCategory::DIAMOND => $this->finish($piece->askingPrice, $piece->askingPrice, $piece->askingPrice, $rates->stonePct, $piece, $rates),
            PieceCategory::GOLD_WITH_DIAMOND => $this->goldWithDiamond($this->requireMarket($market), $piece, $rates),
        };
    }

    private function gold(MarketPrice $market, Piece $piece, PricingRates $rates): PriceBreakdown
    {
        $prices = $this->quotable($market, $piece->karat);
        $making = Money::mul($piece->makingPerGram, $piece->weight);

        $buyerTotal = Money::round4(Money::add(Money::mul($prices->buyersPay, $piece->weight), $making));
        $sellerGross = Money::round4(Money::add(Money::mul($prices->sellersGet, $piece->weight), $making));

        return $this->finish($buyerTotal, $sellerGross, Money::round4($making), $rates->goldPct, $piece, $rates, null, $prices);
    }

    private function goldWithDiamond(MarketPrice $market, Piece $piece, PricingRates $rates): PriceBreakdown
    {
        // No adjustment and no spread on stones: the gold is valued at the unadjusted midpoint.
        $prices = $this->karatPrices($market, $piece->karat);
        $goldValue = Money::round4(Money::mul($prices->mid, $piece->weight));
        $aboveGold = Money::max(Money::sub($piece->askingPrice, $goldValue), '0');

        return $this->finish($piece->askingPrice, $piece->askingPrice, $aboveGold, $rates->stonePct, $piece, $rates, $goldValue, $prices);
    }

    private function finish(
        string $buyerTotal,
        string $sellerGross,
        string $commissionBase,
        string $pct,
        Piece $piece,
        PricingRates $rates,
        ?string $protectedGoldValue = null,
        ?KaratPrices $prices = null,
    ): PriceBreakdown {
        $buyerTotal = Money::round4($buyerTotal);
        $sellerGross = Money::round4($sellerGross);

        $commission = $piece->commissionWaived
            ? '0.0000'
            : Money::round4(Money::max(Money::percent($commissionBase, $pct), $rates->minimumEgp));
        $vat = Money::round4(Money::percent($commission, $rates->vatPct));

        return new PriceBreakdown(
            buyerTotal: $buyerTotal,
            sellerGross: $sellerGross,
            spread: Money::fixed4(Money::sub($buyerTotal, $sellerGross)),
            commission: $commission,
            vat: $vat,
            sellerProceeds: Money::fixed4(Money::sub(Money::sub($sellerGross, $commission), $vat)),
            protectedGoldValue: $protectedGoldValue,
            karatPrices: $prices,
        );
    }

    private function quotable(MarketPrice $market, KaratPricing $karat): KaratPrices
    {
        $prices = $this->karatPrices($market, $karat);

        return $prices->inverted ? throw DomainApiException::priceInverted($karat->code) : $prices;
    }

    private function requireMarket(?MarketPrice $market): MarketPrice
    {
        return $market ?? throw DomainApiException::noGoldPrice();
    }
}
