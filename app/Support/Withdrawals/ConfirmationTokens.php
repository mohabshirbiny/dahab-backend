<?php

namespace App\Support\Withdrawals;

/**
 * The secret in the withdrawal email link (spec 013 research R5): 32 random
 * bytes, base64url. Only the HMAC (keyed by the app key) is stored, so a
 * database read never yields a usable link.
 */
final class ConfirmationTokens
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public static function link(string $token): string
    {
        return config('dahab-withdrawals.confirm_url').'?token='.$token;
    }
}
