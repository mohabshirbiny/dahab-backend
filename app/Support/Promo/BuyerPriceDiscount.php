<?php

namespace App\Support\Promo;

use App\Models\BuyRequest;
use App\Models\PromoCode;
use App\Support\Pricing\Money;

/**
 * A buyer code's discount off the price (docs/features/buyer-price-discount.md): a percentage of the
 * buyer's total (to the piastre, half up) or a fixed amount, paid for by Dahab's commission or, on
 * gold, by the spread — and never more than what pays for it: the ordinary commission net of VAT, or
 * the positive spread. Both limits are read on the buyer's side of the sale (no seller waiver), so
 * what the buyer is shown is what they pay. Pure: the settlement and the preview give it the figures.
 */
final readonly class BuyerPriceDiscount
{
    public function __construct(
        public ?string $percent,
        public ?string $amount,
        public ?string $fundedBy,
    ) {}

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public static function ofCode(?PromoCode $promo): self
    {
        if ($promo === null || $promo->price_mode === 'none' || $promo->price_mode === null) {
            return self::none();
        }

        return new self(
            $promo->price_mode === 'percent' ? (string) $promo->price_percent : null,
            $promo->price_mode === 'amount' ? (string) $promo->price_amount_egp : null,
            $promo->discount_funded_by,
        );
    }

    /** The terms the request snapshotted when it was sent. */
    public static function ofRequest(BuyRequest $request): self
    {
        return $request->price_discount_funded_by === null ? self::none() : new self(
            $request->price_discount_percent === null ? null : (string) $request->price_discount_percent,
            $request->price_discount_amount_egp === null ? null : (string) $request->price_discount_amount_egp,
            $request->price_discount_funded_by,
        );
    }

    public function applies(): bool
    {
        return $this->fundedBy !== null;
    }

    /**
     * @param  string  $buyerTotal  what the buyer would pay without the code
     * @param  string  $spread  the sale's spread (may be negative)
     * @param  string  $ordinaryCommission  the ordinary commission, net of VAT
     */
    public function on(string $buyerTotal, string $spread, string $ordinaryCommission): string
    {
        if (! $this->applies()) {
            return '0.0000';
        }

        $raw = $this->percent !== null
            ? bcadd(bcadd(Money::percent($buyerTotal, $this->percent), '0.005', Money::SCALE), '0', 2).'00'
            : Money::fixed4((string) $this->amount);
        $cap = $this->fundedBy === 'spread' ? $spread : $ordinaryCommission;
        $cap = Money::cmp($cap, '0') > 0 ? Money::fixed4($cap) : '0.0000';

        return Money::cmp($raw, $cap) > 0 ? $cap : Money::fixed4($raw);
    }
}
