<?php

namespace App\Support\Pricing;

use App\Enums\SettingKey;
use App\Models\PriceFeedStatus;

/**
 * Whether the gold price feed is usable right now (spec 005 FR-016). A
 * manual price is accepted only while it is not healthy.
 */
final class FeedHealth
{
    public const HEALTHY = 'healthy';

    public const DOWN = 'down';

    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(private readonly Settings $settings) {}

    public static function configured(): bool
    {
        $config = config('services.gold_feed', []);

        return filled($config['base_url'] ?? null) && filled($config['username'] ?? null) && filled($config['password'] ?? null);
    }

    public function state(): string
    {
        if (! self::configured()) {
            return self::NOT_CONFIGURED;
        }

        $lastSuccess = PriceFeedStatus::query()->find(PriceFeedStatus::PROVIDER)?->last_success_at;
        $staleAfter = $this->settings->integer(SettingKey::PRICEFEED_STALE_AFTER_MINUTES);

        return $lastSuccess !== null && $lastSuccess->greaterThan(now()->subMinutes($staleAfter))
            ? self::HEALTHY
            : self::DOWN;
    }

    public function isHealthy(): bool
    {
        return $this->state() === self::HEALTHY;
    }
}
