<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Http\Resources\Customer\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerTrustedDevice;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/**
 * Sets up "an active, signed-in customer with a token family" in one call and
 * returns a TestResponse that mimics the shape of the old
 * `POST /register/complete` reply, so pre-refactor session tests keep reading
 * `data.customer.*` / `data.session.access_token` / `.refresh_token` exactly
 * as before.
 *
 * The new six-step registration cannot produce a session on its own: submit
 * creates a `pending_verification` customer that must be approved by staff
 * before any login works. Session-flow tests do not care about the
 * registration path — they exercise access/refresh/logout — so this helper
 * skips the whole flow and provisions the state directly, matching the old
 * contract's return shape.
 *
 * Tests that specifically exercise registration (RegisterTest,
 * RegistrationNotificationDeliveryTest) walk the six-step flow themselves.
 *
 * @param  array<string, mixed>  $body  {phone, password?, full_name?, email?, preferred_lang?}
 * @param  array<string, string>  $headers  {X-Device-Id?}
 */
function registerCustomer(array $body = [], array $headers = []): TestResponse
{
    $attrs = array_merge([
        'phone' => '+201000000001',
        'full_name' => 'Test Customer',
        'email' => null,
        'preferred_lang' => 'en',
    ], array_intersect_key($body, array_flip(['phone', 'full_name', 'email', 'preferred_lang'])));

    $customer = Customer::factory()
        ->verified()
        ->withPassword($body['password'] ?? 'correct-horse-battery')
        ->create($attrs);

    // Same derivation as SetRequestContext middleware: sha256(deviceId|platform).
    $fingerprint = isset($headers['X-Device-Id'])
        ? hash('sha256', $headers['X-Device-Id'].'|'.($headers['X-Device-Platform'] ?? 'web'))
        : null;

    if ($fingerprint !== null) {
        CustomerTrustedDevice::query()->create([
            'customer_id' => $customer->customer_id,
            'fingerprint_hash' => $fingerprint,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    $session = app(IssueTokenFamilyAction::class)->forCustomer($customer);

    return TestResponse::fromBaseResponse(
        response()->json([
            'data' => [
                'customer' => (new CustomerResource($customer))->toArray(request()),
                'session' => $session->toArray(),
            ],
        ], 201)
    );
}

/** A real (tiny) PNG, so content sniffing sees an actual image. */
function pngBytes(): string
{
    ob_start();
    imagepng(imagecreatetruecolor(4, 4));

    return (string) ob_get_clean();
}
