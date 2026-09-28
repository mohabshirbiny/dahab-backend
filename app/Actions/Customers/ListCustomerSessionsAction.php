<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerTrustedDevice;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A customer file's Sign-ins and devices (spec 007 US3 / FR-012): every
 * trusted device, and the sessions still open — one per token family, newest
 * activity first. Sign-out deletes a session's tokens, so ended sessions are
 * not listed; their sign-in and sign-out entries are in History (research R7,
 * as built). Never returns token values, abilities or full fingerprints.
 */
final class ListCustomerSessionsAction
{
    /** Short, stable reference for a device; the full fingerprint hash is not shown. */
    public const DEVICE_REF_LENGTH = 12;

    /** @return array{devices: Collection<int, array<string, mixed>>, sessions: LengthAwarePaginator} */
    public function handle(string $customerId, int $perPage): array
    {
        $customer = Customer::query()->findOrFail($customerId);

        $devices = CustomerTrustedDevice::query()
            ->where('customer_id', $customer->customer_id)
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (CustomerTrustedDevice $d) => [
                'device_ref' => substr($d->fingerprint_hash, 0, self::DEVICE_REF_LENGTH),
                'first_seen_at' => $d->first_seen_at?->toIso8601String(),
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            ]);

        $sessions = DB::table('personal_access_tokens')
            ->where('tokenable_type', $customer->getMorphClass())
            ->where('tokenable_id', $customer->customer_id)
            ->whereNotNull('family_id')
            ->groupBy('family_id')
            // Open while any token of the family is unexpired.
            ->havingRaw('bool_or(expires_at IS NULL OR expires_at > now())')
            ->selectRaw('family_id, min(created_at) AS started_at, max(COALESCE(last_used_at, created_at)) AS last_active_at, max(expires_at) AS expires_at')
            ->orderByDesc('last_active_at')
            ->orderByDesc('family_id')
            ->paginate($perPage);

        return ['devices' => $devices, 'sessions' => $sessions];
    }
}
