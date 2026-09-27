<?php

namespace App\Contracts;

use App\Services\PriceFeed\GoldFeedException;
use App\Services\PriceFeed\GoldQuote;

/**
 * The gold price provider (Technical Spec Part 4 §1). The provider's identity
 * is pending confirmation (OI-4.1); implementations follow only the observed
 * calls, never a guess from the provider's name.
 */
interface GoldPriceFeed
{
    /** @throws GoldFeedException when the provider cannot be read or its answer is unusable */
    public function fetch(): GoldQuote;
}
