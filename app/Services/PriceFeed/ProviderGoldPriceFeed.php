<?php

namespace App\Services\PriceFeed;

use App\Contracts\GoldPriceFeed;
use App\Support\Pricing\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Reads the gold price provider with the two calls the previous platform
 * made (Technical Spec Part 4 §1.2): sign in for a bearer token, then read
 * the Egyptian 24K bid and ask. Credentials come from config (the
 * environment) only. The token is cached; a 401 signs in once more.
 */
final class ProviderGoldPriceFeed implements GoldPriceFeed
{
    private const TOKEN_CACHE_KEY = 'gold_feed.access_token';

    private const TOKEN_TTL_SECONDS = 600;

    private const PRICE_PATH = '/v1/datafeed/METAL_PRICE_TYPE_EGY/xau/price';

    public function fetch(): GoldQuote
    {
        $response = $this->priceCall($this->token());

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->priceCall($this->token());
        }

        if (! $response->successful()) {
            throw GoldFeedException::http('price', $response->status());
        }

        return self::quote($response->json());
    }

    private function token(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, self::TOKEN_TTL_SECONDS, function () {
            $response = $this->send(fn () => Http::timeout($this->config('timeout'))
                ->acceptJson()
                ->post($this->config('base_url').'/v1/auth', [
                    'username' => $this->config('username'),
                    'password' => $this->config('password'),
                ]));

            if (! $response->successful()) {
                throw GoldFeedException::signInRefused($response->status());
            }

            $token = $response->json('access_token');

            return is_string($token) && $token !== '' ? $token : throw GoldFeedException::unusable('the sign-in returned no access_token');
        });
    }

    private function priceCall(string $token): Response
    {
        return $this->send(fn () => Http::timeout($this->config('timeout'))
            ->acceptJson()
            ->withToken($token)
            ->get($this->config('base_url').self::PRICE_PATH));
    }

    /** @param  callable(): Response  $call */
    private function send(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException) {
            throw GoldFeedException::unreachable();
        }
    }

    private static function quote(mixed $body): GoldQuote
    {
        $bid = self::number($body, 'bidPrice');
        $ask = self::number($body, 'askPrice');

        if (Money::cmp($bid, '0') <= 0) {
            throw GoldFeedException::unusable('bidPrice must be above zero');
        }
        if (Money::cmp($ask, $bid) < 0) {
            throw GoldFeedException::unusable('askPrice is below bidPrice');
        }

        return new GoldQuote(Money::round4($bid), Money::round4($ask));
    }

    private static function number(mixed $body, string $field): string
    {
        $value = is_array($body) ? ($body[$field] ?? null) : null;

        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw GoldFeedException::unusable("{$field} is missing or not a number");
        }

        // A float's shortest round-trip form keeps the provider's decimals.
        return is_float($value) ? rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.') : (string) $value;
    }

    private function config(string $key): mixed
    {
        return config('services.gold_feed.'.$key);
    }
}
