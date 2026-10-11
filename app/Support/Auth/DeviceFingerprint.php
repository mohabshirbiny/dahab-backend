<?php

namespace App\Support\Auth;

/**
 * The device fingerprint stored for sign-ins and audit rows: sha256 of the client's
 * `X-Device-Id` and `X-Device-Platform` (default `web`). The id itself is never stored.
 * Shared by SetRequestContext, the `founder:trust-device` command and test factories.
 */
final class DeviceFingerprint
{
    public static function hash(string $deviceId, ?string $platform = null): string
    {
        return hash('sha256', $deviceId.'|'.($platform ?? 'web'));
    }
}
