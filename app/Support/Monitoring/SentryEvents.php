<?php

namespace App\Support\Monitoring;

use App\Exceptions\AuthApiException;
use App\Exceptions\DomainApiException;
use Sentry\Event;
use Sentry\EventHint;

/**
 * What reaches Sentry (config/sentry.php `before_send`).
 *
 * Our own API refusals (wrong password, insufficient funds, an illegal move …)
 * are answers, not faults: a 4xx `AuthApiException` or `DomainApiException`
 * is dropped. A 5xx one — `database_isolation_off` — still goes, as does every
 * other exception. `laravel.log` is unchanged either way.
 */
final class SentryEvents
{
    public static function beforeSend(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;

        if (($exception instanceof DomainApiException || $exception instanceof AuthApiException)
            && $exception->statusCode < 500) {
            return null;
        }

        return $event;
    }
}
