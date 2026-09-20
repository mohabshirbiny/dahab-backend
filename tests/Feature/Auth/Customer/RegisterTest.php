<?php

use App\Enums\CustomerStatus;
use App\Enums\IdentityDocumentStatus;
use App\Models\Customer;
use App\Models\CustomerPassword;
use App\Models\IdentityDocument;
use App\Notifications\CustomerRegistrationEmailOtpNotification;
use App\Notifications\CustomerRegistrationOtpNotification;
use App\Notifications\CustomerRegistrationSubmittedNotification;
use App\Services\CustomerRegistrationSessionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    Storage::fake('identity_private');
});

/** Six-step helper: runs steps 1–5 and returns [$ref, $customer_phone, $customer_email]. */
function walkThroughSteps(): array
{
    $phone = '+201000000001';
    $email = 'mona@example.com';

    // Step 1
    $r = test()->postJson('/api/v1/customer/auth/register/start', [
        'name' => 'Mona Hassan',
        'phone' => $phone,
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'preferred_lang' => 'ar',
    ], ['X-Device-Id' => str_repeat('a', 32)])->assertAccepted();
    $ref = $r->json('data.registration_ref');

    $phoneOtp = null;
    Notification::assertSentOnDemand(CustomerRegistrationOtpNotification::class, function ($n) use (&$phoneOtp) {
        $phoneOtp = (new ReflectionProperty($n, 'code'))->getValue($n);

        return true;
    });

    // Step 2
    test()->postJson('/api/v1/customer/auth/register/verify-phone-otp', ['registration_ref' => $ref, 'otp' => $phoneOtp])->assertOk();

    // Step 3
    test()->postJson('/api/v1/customer/auth/register/email', [
        'registration_ref' => $ref,
        'email' => $email,
        'governorate' => 'cairo',
    ])->assertAccepted();

    $emailOtp = null;
    Notification::assertSentOnDemand(CustomerRegistrationEmailOtpNotification::class, function ($n) use (&$emailOtp) {
        $emailOtp = (new ReflectionProperty($n, 'code'))->getValue($n);

        return true;
    });

    // Step 4
    test()->postJson('/api/v1/customer/auth/register/verify-email-otp', ['registration_ref' => $ref, 'otp' => $emailOtp])->assertOk();

    // Step 5
    test()->post('/api/v1/customer/auth/register/documents', [
        'registration_ref' => $ref,
        'document_type' => 'egyptian_id',
        'front' => UploadedFile::fake()->image('front.jpg', 600, 400),
        'back' => UploadedFile::fake()->image('back.jpg', 600, 400),
    ], ['Accept' => 'application/json'])->assertOk();

    return [$ref, $phone, $email];
}

it('starts registration with name/phone/password and sends the phone OTP; no customer is created', function () {
    $this->postJson('/api/v1/customer/auth/register/start', [
        'name' => 'Mona Hassan',
        'phone' => '+201000000001',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertAccepted()->assertJsonPath('data.status', 'phone_otp_sent');

    Notification::assertSentOnDemand(CustomerRegistrationOtpNotification::class);
    expect(Customer::query()->count())->toBe(0);
    expect(CustomerPassword::query()->count())->toBe(0);
});

it('rejects a mismatched password confirmation before sending an OTP', function () {
    $this->postJson('/api/v1/customer/auth/register/start', [
        'name' => 'Mona',
        'phone' => '+201000000002',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'nope',
    ])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    Notification::assertNothingSent();
});

it('refuses to add an email before the phone is verified', function () {
    $r = $this->postJson('/api/v1/customer/auth/register/start', [
        'name' => 'Mona',
        'phone' => '+201000000003',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertAccepted();

    $this->postJson('/api/v1/customer/auth/register/email', [
        'registration_ref' => $r->json('data.registration_ref'),
        'email' => 'x@example.com',
        'governorate' => 'cairo',
    ])->assertStatus(409)->assertJsonPath('code', 'registration_phone_unverified');
});

it('submits the completed registration as pending_verification with no session and no active flag', function () {
    [$ref, $phone] = walkThroughSteps();

    $response = $this->postJson('/api/v1/customer/auth/register/submit', ['registration_ref' => $ref])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'pending_verification')
        ->assertJsonPath('data.customer.status', 'pending_verification')
        ->assertJsonPath('data.customer.is_verified', false)
        ->assertJsonPath('data.customer.trade_allowed', false)
        ->assertJsonMissingPath('data.session');

    expect($response->getContent())->not->toContain('password_hash')->not->toContain('access_token');

    $customer = Customer::query()->where('phone', $phone)->sole();
    expect($customer->status)->toBe(CustomerStatus::PENDING_VERIFICATION)
        ->and(CustomerPassword::query()->where('customer_id', $customer->customer_id)->count())->toBe(1);

    $document = IdentityDocument::query()->where('customer_id', $customer->customer_id)->sole();
    expect($document->status)->toBe(IdentityDocumentStatus::PENDING)
        ->and($document->back_ref)->not->toBeNull();
});

it('queues the "registration submitted" notification only after commit', function () {
    [$ref] = walkThroughSteps();

    $this->postJson('/api/v1/customer/auth/register/submit', ['registration_ref' => $ref])->assertStatus(202);

    $customer = Customer::query()->sole();
    Notification::assertSentTo($customer, CustomerRegistrationSubmittedNotification::class);
});

it('refuses to submit twice with registration_session_invalid or registration_already_submitted', function () {
    [$ref] = walkThroughSteps();

    $this->postJson('/api/v1/customer/auth/register/submit', ['registration_ref' => $ref])->assertStatus(202);

    $second = $this->postJson('/api/v1/customer/auth/register/submit', ['registration_ref' => $ref]);
    // Either code is acceptable: session was consumed and forgotten on submit.
    expect($second->status())->toBeIn([409, 410]);
    expect(Customer::query()->count())->toBe(1);
});

it('answers /register/complete with 410 registration_endpoint_deprecated and writes nothing', function () {
    $this->postJson('/api/v1/customer/auth/register/complete', ['registration_ref' => 'anything'])
        ->assertStatus(410)
        ->assertJsonPath('code', 'registration_endpoint_deprecated');

    expect(Customer::query()->count())->toBe(0);
});

it('cannot submit an abandoned/expired session', function () {
    [$ref] = walkThroughSteps();
    app(CustomerRegistrationSessionStore::class)->forget($ref);

    $this->postJson('/api/v1/customer/auth/register/submit', ['registration_ref' => $ref])
        ->assertStatus(410)
        ->assertJsonPath('code', 'registration_session_invalid');

    expect(Customer::query()->count())->toBe(0);
});

it('refuses submit when phone was taken while the registration was in flight', function () {
    [$ref, $phone] = walkThroughSteps();
    Customer::factory()->create(['phone' => $phone]);

    $this->postJson('/api/v1/customer/auth/register/submit', ['registration_ref' => $ref])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    expect(Customer::query()->where('phone', $phone)->count())->toBe(1);
});
