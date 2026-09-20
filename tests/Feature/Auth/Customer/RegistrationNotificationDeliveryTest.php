<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * The other registration tests fake notifications, which proves dispatch but
 * never executes a channel. This one fakes nothing: the sms channel, the
 * LogSmsSender and the Blade mailable all really run against the log and
 * array drivers. It is what catches an unregistered channel, a missing view
 * or an unrenderable template through the entire 6-step registration.
 */
it('delivers the OTP and the submitted-notification through the real channel stack', function () {
    config(['mail.default' => 'array', 'sms.default' => 'log']);
    Storage::fake('identity_private');

    $sms = [];
    Log::listen(function ($message) use (&$sms) {
        if ($message->message === 'sms.sent') {
            $sms[] = $message->context['message'];
        }
    });

    // Step 1
    $ref = $this->postJson('/api/v1/customer/auth/register/start', [
        'name' => 'Live Applicant',
        'phone' => '+201000077777',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'preferred_lang' => 'en',
    ])->assertAccepted()->json('data.registration_ref');

    expect($sms)->toHaveCount(1);
    preg_match('/\b(\d{6})\b/', $sms[0], $m);
    $phoneOtp = $m[1] ?? null;
    expect($phoneOtp)->not->toBeNull();

    // Step 2
    $this->postJson('/api/v1/customer/auth/register/verify-phone-otp', ['registration_ref' => $ref, 'otp' => $phoneOtp])->assertOk();

    // Step 3 — email OTP goes via mail
    $this->postJson('/api/v1/customer/auth/register/email', [
        'registration_ref' => $ref,
        'email' => 'live@dahab.local',
        'governorate' => 'cairo',
    ])->assertAccepted();

    $mails = Mail::getSymfonyTransport()->messages();
    expect($mails)->toHaveCount(1);
    preg_match('/\b(\d{6})\b/', $mails[0]->getOriginalMessage()->getHtmlBody(), $m);
    $emailOtp = $m[1] ?? null;
    expect($emailOtp)->not->toBeNull();

    // Step 4
    $this->postJson('/api/v1/customer/auth/register/verify-email-otp', ['registration_ref' => $ref, 'otp' => $emailOtp])->assertOk();

    // Step 5
    $this->post('/api/v1/customer/auth/register/documents', [
        'registration_ref' => $ref,
        'document_type' => 'egyptian_id',
        'front' => UploadedFile::fake()->image('f.jpg', 600, 400),
        'back' => UploadedFile::fake()->image('b.jpg', 600, 400),
    ], ['Accept' => 'application/json'])->assertOk();

    // Step 6 — real SMS + mail delivered
    $this->postJson('/api/v1/customer/auth/register/submit', ['registration_ref' => $ref])->assertStatus(202);

    $customer = Customer::query()->sole();

    expect($sms)->toHaveCount(2)
        ->and($sms[1])->toContain($customer->display_ref);

    $mails = iterator_to_array(Mail::getSymfonyTransport()->messages());
    // Two mails: email OTP + submitted notification.
    expect(count($mails))->toBeGreaterThanOrEqual(2);

    $lastMail = end($mails)->getOriginalMessage();
    expect($lastMail->getTo()[0]->getAddress())->toBe('live@dahab.local')
        ->and($lastMail->getHtmlBody())->toContain($customer->display_ref);
});
