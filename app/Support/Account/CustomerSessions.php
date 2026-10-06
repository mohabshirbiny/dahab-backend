<?php

namespace App\Support\Account;

use App\Models\Customer;
use App\Models\CustomerTrustedDevice;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A customer's sessions (token families) and trusted devices (spec 017
 * research R4). Signing out deletes a family's tokens, which is what makes
 * Sanctum refuse them at once; forgetting a device deletes its trusted row,
 * so the next sign-in from it needs a code.
 */
final class CustomerSessions
{
    /** End every session except `$keepFamily`. @return int the families ended */
    public function endOthers(Customer $customer, ?string $keepFamily): int
    {
        $families = $this->tokens($customer)
            ->when($keepFamily !== null, fn ($q) => $q->where(fn ($w) => $w->where('family_id', '!=', $keepFamily)->orWhereNull('family_id')))
            ->distinct()->pluck('family_id')->filter()->count();

        $this->tokens($customer)
            ->when($keepFamily !== null, fn ($q) => $q->where(fn ($w) => $w->where('family_id', '!=', $keepFamily)->orWhereNull('family_id')))
            ->delete();

        return $families;
    }

    /** Forget every trusted device except `$keepFingerprint`. @return int the devices forgotten */
    public function forgetOthers(Customer $customer, ?string $keepFingerprint): int
    {
        return CustomerTrustedDevice::query()->where('customer_id', $customer->customer_id)
            ->when($keepFingerprint !== null, fn ($q) => $q->where('fingerprint_hash', '!=', $keepFingerprint))
            ->delete();
    }

    /**
     * Sign one session out; when it is tied to a device, end every session of
     * that device and forget it. @return array{sessions: int, device_forgotten: bool}|null null when not the customer's
     */
    public function signOut(Customer $customer, string $familyId): ?array
    {
        $row = $this->tokens($customer)->where('family_id', $familyId)->first(['device_fingerprint_hash']);
        if ($row === null) {
            return null;
        }

        $fingerprint = $row->device_fingerprint_hash;
        $families = $fingerprint === null
            ? collect([$familyId])
            : $this->tokens($customer)->where(fn ($q) => $q->where('device_fingerprint_hash', $fingerprint)->orWhere('family_id', $familyId))
                ->distinct()->pluck('family_id')->filter();

        $this->tokens($customer)->whereIn('family_id', $families->all())->delete();

        $forgotten = $fingerprint !== null && CustomerTrustedDevice::query()
            ->where('customer_id', $customer->customer_id)->where('fingerprint_hash', $fingerprint)->delete() > 0;

        return ['sessions' => $families->count(), 'device_forgotten' => $forgotten];
    }

    private function tokens(Customer $customer): Builder
    {
        return DB::table('personal_access_tokens')
            ->where('tokenable_type', $customer->getMorphClass())
            ->where('tokenable_id', $customer->customer_id);
    }
}
