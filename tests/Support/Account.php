<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\CustomerTrustedDevice;
use App\Notifications\PhoneChangeCodeNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Spec 017 test helpers: real sign-ins on named devices, and the account endpoints. */
final class Account
{
    public const ME = '/api/v1/customer/me';

    public const PASSWORD = 'correct-horse-battery';

    public static function customer(array $attributes = [], bool $verified = true): Customer
    {
        $factory = Customer::factory()->withPassword(self::PASSWORD);

        return ($verified ? $factory->verified() : $factory->pendingVerification())->create($attributes);
    }

    public static function fingerprint(string $deviceId, string $platform = 'ios'): string
    {
        return hash('sha256', $deviceId.'|'.$platform);
    }

    public static function trustDevice(Customer $customer, string $deviceId, string $platform = 'ios'): void
    {
        CustomerTrustedDevice::query()->firstOrCreate(
            ['customer_id' => $customer->customer_id, 'fingerprint_hash' => self::fingerprint($deviceId, $platform)],
            ['first_seen_at' => now(), 'last_seen_at' => now()],
        );
    }

    /**
     * Sign in through the API from a trusted device. @return array{access: string, family: string}
     */
    public static function signIn(TestCase $test, Customer $customer, string $deviceId = 'device-a', string $platform = 'ios'): array
    {
        self::trustDevice($customer, $deviceId, $platform);
        app('auth')->forgetGuards();
        $session = $test->withoutToken()
            ->withHeaders(['X-Device-Id' => $deviceId, 'X-Device-Platform' => $platform])
            ->postJson('/api/v1/customer/auth/login', ['phone' => $customer->phone, 'password' => self::PASSWORD])
            ->assertOk()->json('data.session');

        return ['access' => $session['access_token'], 'family' => $session['family_id']];
    }

    /** The next request as that session, from that device. */
    public static function as(TestCase $test, string $access, string $deviceId = 'device-a', string $platform = 'ios'): TestCase
    {
        app('auth')->forgetGuards();

        return $test->withToken($access)->withHeaders(['X-Device-Id' => $deviceId, 'X-Device-Platform' => $platform]);
    }

    /** @return array<string, string> */
    public static function key(?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
    }

    public static function post(TestCase $test, string $access, string $path, array $body = [], ?string $key = null, string $deviceId = 'device-a'): TestResponse
    {
        return self::as($test, $access, $deviceId)->postJson(self::ME.$path, $body, self::key($key));
    }

    /** The code last texted to `$phone` for a phone change (Notification::fake()). */
    public static function phoneCode(string $phone): string
    {
        $mine = null;
        Notification::assertSentOnDemand(PhoneChangeCodeNotification::class,
            function (PhoneChangeCodeNotification $n, array $channels, AnonymousNotifiable $to) use ($phone, &$mine) {
                if (($to->routes['sms'] ?? null) !== $phone) {
                    return false;
                }
                $mine = $n;

                return true;
            });
        preg_match('/\b(\d{6})\b/', $mine->toSms(new AnonymousNotifiable)->content, $m);

        return $m[1];
    }
}
