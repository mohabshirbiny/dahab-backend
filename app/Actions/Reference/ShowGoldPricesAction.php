<?php

namespace App\Actions\Reference;

use App\Enums\PriceSource;
use App\Exceptions\DomainApiException;
use App\Support\Pricing\FeedHealth;
use App\Support\Pricing\PricingContext;

/**
 * Today's prices for everyone (spec 015 FR-021, research R13): per enabled
 * karat what sellers get and what buyers pay per gram, from the spec 005
 * calculator at the current gold price — never the provider's bid/ask or the
 * adjustments. A karat whose prices cross is left out. No current price →
 * price_unavailable.
 */
final class ShowGoldPricesAction
{
    public function __construct(
        private readonly PricingContext $pricing,
        private readonly FeedHealth $feed,
    ) {}

    /** @return array{price_at: string, feed_state: string, karats: list<array{code: int, label: string, sellers_get: string, buyers_pay: string}>} */
    public function handle(): array
    {
        $price = $this->pricing->currentPrice() ?? throw DomainApiException::priceUnavailable();
        $market = $this->pricing->market($price);

        $karats = $this->pricing->karatPrices($market)
            ->filter(fn (array $row) => $row['karat']->is_enabled && ! $row['prices']->inverted)
            ->map(fn (array $row) => [
                'code' => $row['karat']->karat_code,
                'label' => $row['karat']->karat_code.'K',
                'sellers_get' => $row['prices']->sellersGet,
                'buyers_pay' => $row['prices']->buyersPay,
            ])->values()->all();

        return [
            'price_at' => $price->effective_at->toIso8601String(),
            'feed_state' => match (true) {
                $price->source === PriceSource::MANUAL => 'manual',
                $this->feed->isHealthy() => 'live',
                default => 'stale',
            },
            'karats' => $karats,
        ];
    }
}
