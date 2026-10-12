<?php

namespace App\Support\Promo;

use App\Enums\IdentityDocumentStatus;
use App\Models\Customer;
use App\Models\IdentityDocument;

/**
 * The key a "once per ID" limit counts against (spec 023 research R7, R8; D23-9).
 *
 * The reviewer keys the national-ID number at approval; only a keyed hash of it is stored
 * (`identity_document.id_number_hash`), never the number, and no API returns the hash. A
 * customer's key is the hash of their latest verified document that has one, so two accounts of
 * one person share a key. A customer approved before the number was captured has none and falls
 * back to the account (`account:<id>`), which is weaker: it is stated in the spec 023 report.
 */
final class CustomerIdKey
{
    /** The keyed hash of a 14-digit national-ID number. */
    public static function hash(string $number): string
    {
        return hash_hmac('sha256', 'national-id|'.$number, (string) config('app.key'));
    }

    /** The stored key of a customer, or the account fallback. */
    public static function for(Customer|string $customer): string
    {
        $id = $customer instanceof Customer ? $customer->customer_id : $customer;

        $hash = IdentityDocument::query()
            ->where('customer_id', $id)
            ->where('status', IdentityDocumentStatus::VERIFIED->value)
            ->whereNotNull('id_number_hash')
            ->orderByDesc('reviewed_at')
            ->value('id_number_hash');

        return $hash ?? 'account:'.$id;
    }

    /** `id` when a national ID backs the key, `account` when it is the fallback (audit, never the key). */
    public static function kind(string $key): string
    {
        return str_starts_with($key, 'account:') ? 'account' : 'id';
    }
}
