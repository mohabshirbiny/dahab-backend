<?php

namespace App\Actions\Saved;

use App\Enums\SettingKey;
use App\Exceptions\DomainApiException;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\SavedListing;
use App\Support\DatabaseActor;
use App\Support\Pricing\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saved pieces (spec 017 FR-040): a customer keeps pieces on the market
 * (live or reserved) to come back to; a saved piece never locks a price. Other
 * sellers' pieces are read in the read-only `market` scope; the saved rows in
 * the customer's own scope. At most `saved.max_per_customer`.
 */
final class SavedPiecesAction
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array{rows: Collection<int, SavedListing>, listings: Collection<string, Listing>}
     *                                                                                           the saved rows (newest first) and, keyed by id, the pieces still on the market
     */
    public function list(Customer $customer, ?string $listingId = null): array
    {
        $rows = SavedListing::query()->where('customer_id', $customer->customer_id)
            ->when($listingId !== null, fn ($q) => $q->where('listing_id', $listingId))
            ->orderByDesc('saved_at')->orderByDesc('listing_id')->get();

        $ids = $rows->pluck('listing_id')->all();
        $listings = $ids === [] ? collect() : DatabaseActor::market(fn () => Listing::query()->publiclyVisible()
            ->with(['pieceType', 'media', 'branches'])->whereIn('listing_id', $ids)->get()->keyBy('listing_id'));

        return ['rows' => $rows, 'listings' => $listings];
    }

    public function save(Customer $customer, string $listingId): SavedListing
    {
        $listing = DatabaseActor::market(fn () => Listing::query()->publiclyVisible()->with('pieceType')->find($listingId))
            ?? throw DomainApiException::listingNotSaveable();

        return DB::transaction(function () use ($customer, $listing) {
            // One customer's saves are serialised on their own row, so the cap holds under racing requests.
            Customer::query()->whereKey($customer->customer_id)->lockForUpdate()->first();

            $existing = SavedListing::query()->where('customer_id', $customer->customer_id)->where('listing_id', $listing->listing_id)->first();
            if ($existing !== null) {
                return $existing;
            }

            $limit = $this->settings->integer(SettingKey::SAVED_MAX_PER_CUSTOMER);
            if (SavedListing::query()->where('customer_id', $customer->customer_id)->count() >= $limit) {
                throw DomainApiException::savedLimitReached($limit);
            }

            $saved = new SavedListing;
            $saved->forceFill([
                'customer_id' => $customer->customer_id,
                'listing_id' => $listing->listing_id,
                'summary' => [
                    'category' => $listing->category->value,
                    'piece_type' => ['id' => $listing->piece_type_id, 'name_en' => $listing->pieceType?->name_en, 'name_ar' => $listing->pieceType?->name_ar],
                    'karat' => $listing->karat_code,
                    'weight_g' => $listing->stated_weight_g === null ? null : bcadd((string) $listing->stated_weight_g, '0', 3),
                ],
                'saved_at' => now(),
            ])->save();

            return $saved;
        });
    }

    public function unsave(Customer $customer, string $listingId): void
    {
        SavedListing::query()->where('customer_id', $customer->customer_id)->where('listing_id', $listingId)->delete();
    }
}
