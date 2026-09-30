<?php

namespace App\Support\Listings;

use App\Enums\PieceCategory;
use App\Exceptions\DomainApiException;
use App\Models\Karat;
use App\Models\Listing;
use App\Support\Pricing\KaratPrices;
use App\Support\Pricing\KaratPricing;
use App\Support\Pricing\MarketPrice;
use App\Support\Pricing\Money;
use App\Support\Pricing\Piece;
use App\Support\Pricing\PriceCalculator;
use App\Support\Pricing\PricingContext;
use App\Support\Pricing\PricingRates;
use Illuminate\Http\Request;
use LogicException;

/**
 * Prices listings with the single calculator of Part 3 §2 (spec 005), so the
 * market, the seller and staff all see the same figures (spec 010 FR-023).
 * The gold price, the karats and the rates are loaded once per instance;
 * bind it per request, not as a singleton.
 *
 * A listing that cannot be quoted — no gold price yet, an inverted karat, a
 * karat without adjustments — gets null figures, never an error.
 */
final class ListingPricer
{
    private bool $loaded = false;

    private ?MarketPrice $market = null;

    private PricingRates $rates;

    /** @var array<int, KaratPricing> */
    private array $karats = [];

    public function __construct(
        private readonly PricingContext $context,
        private readonly PriceCalculator $calculator,
    ) {}

    /**
     * The pricer of this request: every listing in one response is priced
     * from the same gold price, loaded once.
     */
    public static function for(Request $request): self
    {
        $pricer = $request->attributes->get(self::class);

        if (! $pricer instanceof self) {
            $pricer = app(self::class);
            $request->attributes->set(self::class, $pricer);
        }

        return $pricer;
    }

    public function quote(Listing $listing): ListingQuote
    {
        $this->load();

        $gold = $listing->category === PieceCategory::GOLD;
        $piece = $this->piece($listing);

        try {
            $breakdown = $piece === null ? null : $this->calculator->breakdown($this->market, $piece, $this->rates);
        } catch (DomainApiException) {
            // no_gold_price or price_inverted: nothing to quote right now.
            $breakdown = null;
        }

        if (! $gold) {
            // A fixed asking price: what the buyer pays does not depend on the gold price.
            return new ListingQuote(
                currentPrice: Money::round4((string) $listing->asking_price),
                priceIsIndicative: false,
                ratePerGram: null,
                goldValue: null,
                makingTotal: null,
                youWouldReceive: $breakdown?->sellerProceeds,
            );
        }

        if ($breakdown === null) {
            return new ListingQuote(null, true, null, null, null, null);
        }

        $rate = $breakdown->karatPrices->buyersPay;

        return new ListingQuote(
            currentPrice: $breakdown->buyerTotal,
            priceIsIndicative: true,
            ratePerGram: $rate,
            goldValue: Money::round4(Money::mul($rate, (string) $listing->stated_weight_g)),
            makingTotal: Money::round4(Money::mul((string) $listing->making_charge_per_g, (string) $listing->stated_weight_g)),
            youWouldReceive: $breakdown->sellerProceeds,
        );
    }

    /**
     * What a buyer pays per gram of each karat that can be quoted right now,
     * for ordering the market by price in SQL (research R8). The figure shown
     * always comes from quote().
     *
     * @return array<int, string> karat code => EGP per gram
     */
    public function buyersPayByKarat(): array
    {
        $this->load();

        if ($this->market === null) {
            return [];
        }

        $rates = [];

        foreach ($this->karats as $code => $pricing) {
            $prices = $this->calculator->karatPrices($this->market, $pricing);

            if (! $prices->inverted) {
                $rates[$code] = $prices->buyersPay;
            }
        }

        return $rates;
    }

    /** The karat prices behind a quote, for callers that show the rate (null when it cannot be quoted). */
    public function karatPrices(int $karatCode): ?KaratPrices
    {
        $this->load();

        return $this->market === null || ! isset($this->karats[$karatCode])
            ? null
            : $this->calculator->karatPrices($this->market, $this->karats[$karatCode]);
    }

    private function piece(Listing $listing): ?Piece
    {
        if ($listing->category === PieceCategory::DIAMOND) {
            return Piece::diamond((string) $listing->asking_price);
        }

        $karat = $this->karats[(int) $listing->karat_code] ?? null;

        if ($karat === null) {
            return null;
        }

        return $listing->category === PieceCategory::GOLD
            ? Piece::gold($karat, (string) $listing->stated_weight_g, (string) $listing->making_charge_per_g)
            : Piece::goldWithDiamond($karat, (string) $listing->stated_weight_g, (string) $listing->asking_price);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->market = $this->context->market();
        $this->rates = $this->context->rates();

        try {
            foreach ($this->context->karats() as $row) {
                $this->karats[$row['pricing']->code] = $row['pricing'];
            }
        } catch (LogicException) {
            // A karat without its price adjustments: load the others one by one.
            $this->karats = [];

            foreach (Karat::query()->pluck('karat_code') as $code) {
                try {
                    $this->karats[(int) $code] = $this->context->pricing((int) $code);
                } catch (LogicException) {
                    continue;
                }
            }
        }

        $this->loaded = true;
    }
}
