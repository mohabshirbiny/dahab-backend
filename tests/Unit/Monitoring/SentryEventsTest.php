<?php

use App\Enums\AuthErrorCode;
use App\Exceptions\AuthApiException;
use App\Exceptions\DomainApiException;
use App\Support\Monitoring\SentryEvents;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Logs\Log;
use Sentry\Logs\LogLevel;

function sentryHint(Throwable $e): EventHint
{
    return EventHint::fromArray(['exception' => $e]);
}

it('drops our own 4xx API refusals', function () {
    $event = Event::createEvent();

    expect(SentryEvents::beforeSend($event, sentryHint(DomainApiException::insufficientFunds())))->toBeNull()
        ->and(SentryEvents::beforeSend($event, sentryHint(new AuthApiException(AuthErrorCode::INVALID_CREDENTIALS, 401, 'Invalid credentials.'))))->toBeNull();
});

it('keeps a 5xx refusal and every other exception', function () {
    $event = Event::createEvent();

    expect(SentryEvents::beforeSend($event, sentryHint(DomainApiException::databaseIsolationOff())))->toBe($event)
        ->and(SentryEvents::beforeSend($event, sentryHint(new RuntimeException('boom'))))->toBe($event)
        ->and(SentryEvents::beforeSend($event, null))->toBe($event);
});

function sentryLog(string $body, array $attributes): Log
{
    $log = new Log(microtime(true), str_repeat('a', 32), LogLevel::info(), $body);
    foreach ($attributes as $key => $value) {
        $log->setAttribute($key, $value);
    }

    return $log;
}

it('never sends a logged SMS, as a log or as a breadcrumb', function () {
    expect(SentryEvents::beforeSendLog(sentryLog('sms.sent', ['to' => '+201000000001', 'message' => 'Your code is 123456'])))->toBeNull()
        ->and(SentryEvents::beforeBreadcrumb(new Breadcrumb('info', Breadcrumb::TYPE_DEFAULT, 'log.info', 'sms.sent', ['to' => '+201000000001', 'message' => 'Your code is 123456'])))->toBeNull();
});

it('strips IPs, phones, emails, codes and tokens and keeps the rest', function () {
    $log = SentryEvents::beforeSendLog(sentryLog('auth.customer.registration_otp_failed', [
        'channel' => 'sms', 'attempts' => 2, 'ip' => '196.0.0.1', 'customer.Email' => 'a@b.test', 'sentry.origin' => 'auto.logger.monolog',
    ]));

    expect(array_keys($log->attributes()->all()))->toEqualCanonicalizing(['channel', 'attempts', 'sentry.origin']);

    $crumb = SentryEvents::beforeBreadcrumb(new Breadcrumb('info', Breadcrumb::TYPE_DEFAULT, 'log.info', 'auth.customer.registration_otp_failed', [
        'channel' => 'sms', 'ip' => '196.0.0.1', 'phone' => '+201000000001', 'code' => '123456', 'token' => 'x',
    ]));

    expect($crumb->getMetadata())->toBe(['channel' => 'sms']);
});
