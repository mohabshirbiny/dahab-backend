<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Generic JSON-over-HTTP provider driver.
 *
 * Nothing here is provider-specific: the URL, the auth style and the three
 * payload field names all come from `config/sms.php`, so pointing this at a
 * real gateway is an .env change. If the chosen provider needs a shape this
 * cannot express (XML, multi-recipient batching, signed requests), add a
 * sibling driver rather than bending this one.
 *
 * @see config/sms.php
 */
final class HttpSmsSender implements SmsSender
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly string $from,
    ) {}

    public function send(string $to, string $message): void
    {
        $baseUrl = $this->config['base_url'] ?? null;

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new SmsDeliveryException('SMS_BASE_URL is not configured for the http driver.');
        }

        $fields = $this->config['fields'];

        try {
            $response = $this->client()->post((string) $this->config['endpoint'], [
                $fields['to'] => $to,
                $fields['message'] => $message,
                $fields['sender'] => $this->from,
            ]);
        } catch (ConnectionException $e) {
            throw new SmsDeliveryException('SMS provider unreachable: '.$e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            throw new SmsDeliveryException('SMS request failed: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            // The body can echo the recipient number; keep it out of the message.
            throw new SmsDeliveryException('SMS provider returned HTTP '.$response->status().'.');
        }
    }

    private function client(): PendingRequest
    {
        $key = (string) ($this->config['api_key'] ?? '');
        $secret = (string) ($this->config['api_secret'] ?? '');

        $client = Http::baseUrl((string) $this->config['base_url'])
            ->timeout((int) $this->config['timeout'])
            ->retry((int) $this->config['retries'], (int) $this->config['retry_delay_ms'], throw: false)
            ->acceptJson()
            ->asJson();

        return match ($this->config['auth']) {
            'bearer' => $client->withToken($key),
            'basic' => $client->withBasicAuth($key, $secret),
            'header' => $client->withHeaders([(string) $this->config['auth_header'] => $key]),
            'query' => $client->withQueryParameters([(string) $this->config['auth_query_key'] => $key]),
            default => $client,
        };
    }
}
