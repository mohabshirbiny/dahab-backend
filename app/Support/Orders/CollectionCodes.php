<?php

namespace App\Support\Orders;

use App\Exceptions\DomainApiException;
use App\Models\OrderCollection;
use App\Models\SellerReturn;
use Carbon\CarbonImmutable;

/**
 * The codes that release a piece at the counter (spec 012 FR-015, FR-019,
 * FR-020, research R10): 6 digits, checked against an HMAC of the code with
 * the app key, compared in constant time. The plain code is also stored
 * encrypted (the model casts it) so its owner can read it in the app; it is
 * never returned to staff or in a list. Five wrong codes lock the handover
 * for fifteen minutes (config/dahab-orders.php).
 */
final class CollectionCodes
{
    public function issue(): string
    {
        $length = (int) config('dahab-orders.code_length', 6);

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }

    public function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    public function matches(string $code, string $hash): bool
    {
        return hash_equals($hash, $this->hash($code));
    }

    /** Refuse while the handover is locked (429 with the seconds left). */
    public function assertNotLocked(OrderCollection|SellerReturn $holder): void
    {
        $until = $holder->locked_until;
        $now = CarbonImmutable::now();

        if ($until !== null && $until->greaterThan($now)) {
            throw DomainApiException::handoverLocked((int) ceil($now->diffInSeconds($until, true)));
        }
    }

    /**
     * Count a wrong code on a locked row and save it. Returns the attempts left
     * before the lock; at the limit the row is locked and the count restarts.
     */
    public function registerFailure(OrderCollection|SellerReturn $holder): int
    {
        $max = (int) config('dahab-orders.handover_max_attempts', 5);
        $attempts = $holder->failed_attempts + 1;

        if ($attempts >= $max) {
            $holder->failed_attempts = 0;
            $holder->locked_until = CarbonImmutable::now()->addMinutes((int) config('dahab-orders.handover_lock_minutes', 15));
            $holder->save();

            return 0;
        }

        $holder->failed_attempts = $attempts;
        $holder->save();

        return $max - $attempts;
    }
}
