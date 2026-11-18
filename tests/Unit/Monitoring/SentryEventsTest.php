<?php

use App\Enums\AuthErrorCode;
use App\Exceptions\AuthApiException;
use App\Exceptions\DomainApiException;
use App\Support\Monitoring\SentryEvents;
use Sentry\Event;
use Sentry\EventHint;

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
