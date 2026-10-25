<?php

namespace App\Actions\BuyRequests;

use App\Actions\BuyRequests\Concerns\RunsInQueue;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Support\BuyRequests\DepositRule;
use App\Support\Listings\ListingPricer;
use App\Support\MarketMakers\PromoCodeGate;
use App\Support\Pricing\Money;
use App\Support\Promo\BuyerPriceDiscount;

/**
 * What a buyer's code would do on one piece, shown on the piece page before the request is sent
 * (owner request 2026-10-26): the price, the discount, the total and the deposit. The same gate as
 * the request (`promo_code_invalid` with its reason) — but nothing is recorded, so asking never
 * spends the code nor counts as a use. Figures are indicative: the settlement works them out again
 * on IGI's weight and the rates locked at the request.
 */
final class PreviewBuyerCodeAction
{
    use RunsInQueue;

    public function __construct(
        private readonly PromoCodeGate $promos,
        private readonly DepositRule $rule,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Customer $buyer, string $listingId, string $code): array
    {
        return $this->inQueue(function () use ($buyer, $listingId, $code) {
            $listing = Listing::query()->whereKey($listingId)->notHiddenByPause()->firstOrFail();
            if ($listing->seller_id === $buyer->customer_id) {
                throw DomainApiException::cannotBuyOwnListing();
            }
            if (! $listing->state->isPublic()) {
                throw DomainApiException::listingNotPurchasable();
            }

            $pricer = app(ListingPricer::class);
            $price = $pricer->quote($listing)->currentPrice;
            $ordinary = $pricer->ordinary($listing);
            if ($price === null || $ordinary === null) {
                throw DomainApiException::priceUnavailable();
            }

            $promo = $this->promos->validate($buyer, $listing, $price, strtoupper(trim($code)));

            $effect = match (true) {
                (bool) $promo->gives_spread => 'spread',
                $promo->price_mode !== null && $promo->price_mode !== 'none' => 'price',
                $promo->deposit_mode !== 'none' => 'deposit',
                default => 'none',
            };
            $discount = match ($effect) {
                'spread' => Money::cmp($ordinary->spread, '0') > 0 ? Money::fixed4($ordinary->spread) : '0.0000',
                'price' => BuyerPriceDiscount::ofCode($promo)->on($price, $ordinary->spread, $ordinary->commission),
                default => '0.0000',
            };
            $fullDeposit = $this->rule->deposit($price);

            return [
                'code' => $promo->code,
                'effect' => $effect,
                'price_discount' => $effect !== 'price' ? null : [
                    'percent' => $promo->price_mode === 'percent' ? Money::fixed4((string) $promo->price_percent) : null,
                    'amount_egp' => $promo->price_mode === 'amount' ? Money::fixed4((string) $promo->price_amount_egp) : null,
                ],
                'price_before_egp' => Money::fixed4($price),
                'discount_egp' => $discount,
                'total_egp' => Money::fixed4(Money::sub($price, $discount)),
                'deposit_full_egp' => $fullDeposit,
                'deposit_egp' => $this->rule->discounted($fullDeposit, $promo),
                'indicative' => true,
            ];
        });
    }
}
