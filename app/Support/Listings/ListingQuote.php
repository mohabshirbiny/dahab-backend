<?php

namespace App\Support\Listings;

/**
 * A listing's figures at the current gold price (spec 010 FR-023, research
 * R8). Never stored; money is a 4-dp string or null when no price can be
 * quoted. `priceIsIndicative` is true for gold (it follows the rate until a
 * buy request locks it) and false for a fixed asking price.
 */
final readonly class ListingQuote
{
    public function __construct(
        public ?string $currentPrice,
        public bool $priceIsIndicative,
        public ?string $ratePerGram,
        public ?string $goldValue,
        public ?string $makingTotal,
        public ?string $youWouldReceive,
    ) {}

    public function priceAvailable(): bool
    {
        return $this->currentPrice !== null;
    }

    /** @return array{rate_per_gram: string, gold_value: string, making_total: string}|null gold only */
    public function priceParts(): ?array
    {
        return $this->ratePerGram === null ? null : [
            'rate_per_gram' => $this->ratePerGram,
            'gold_value' => (string) $this->goldValue,
            'making_total' => (string) $this->makingTotal,
        ];
    }
}
