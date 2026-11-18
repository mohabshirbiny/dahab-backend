<?php

namespace App\Support\Monitoring;

use App\Exceptions\AuthApiException;
use App\Exceptions\DomainApiException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Logs\Log;

/**
 * What reaches Sentry (config/sentry.php `before_send`, `before_send_log`, `before_breadcrumb`).
 *
 * Our own API refusals (wrong password, insufficient funds, an illegal move …)
 * are answers, not faults: a 4xx `AuthApiException` or `DomainApiException`
 * is dropped. A 5xx one — `database_isolation_off` — still goes, as does every
 * other exception. `laravel.log` is unchanged either way.
 *
 * Log lines reach Sentry twice: as logs (the `sentry_logs` channel) and as
 * breadcrumbs on the next error, whatever channel they were written to. The
 * text of a logged SMS (`sms.sent`: the number and the message, codes included)
 * never goes; neither do IP addresses, phone numbers, emails, codes or tokens
 * in a line's context.
 */
final class SentryEvents
{
    /** Log lines that never go to Sentry. */
    private const DROPPED_MESSAGES = ['sms.sent'];

    /** Context keys removed from logs and breadcrumbs (compared on the last dotted part, any case). */
    private const PRIVATE_KEYS = ['ip', 'to', 'phone', 'email', 'code', 'otp', 'token', 'password'];

    public static function beforeSend(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;

        if (($exception instanceof DomainApiException || $exception instanceof AuthApiException)
            && $exception->statusCode < 500) {
            return null;
        }

        return $event;
    }

    public static function beforeSendLog(Log $log): ?Log
    {
        if (in_array($log->getBody(), self::DROPPED_MESSAGES, true)) {
            return null;
        }

        foreach (array_keys($log->attributes()->all()) as $key) {
            if (self::isPrivate($key)) {
                $log->attributes()->forget($key);
            }
        }

        return $log;
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        if (in_array($breadcrumb->getMessage(), self::DROPPED_MESSAGES, true)) {
            return null;
        }

        foreach (array_keys($breadcrumb->getMetadata()) as $key) {
            if (self::isPrivate((string) $key)) {
                $breadcrumb = $breadcrumb->withoutMetadata((string) $key);
            }
        }

        return $breadcrumb;
    }

    private static function isPrivate(string $key): bool
    {
        $last = strtolower((string) last(explode('.', $key)));

        return in_array($last, self::PRIVATE_KEYS, true);
    }
}
