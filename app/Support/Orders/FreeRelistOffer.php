<?php

namespace App\Support\Orders;

use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderCollection;
use Carbon\CarbonImmutable;

/**
 * The buyer's free-relist offer of a collected piece (spec 018 research R10).
 * Derived, never stored as a status: `none` when no window was stored (setting
 * 0, hours unknown, or the sale was itself a free relist); `used` once a
 * listing links to the order; `expired` after the stored end; else `open`.
 * Server time is the authority; the app's countdown is only a display of
 * `ends_at`.
 */
final class FreeRelistOffer
{
    public const NONE = 'none';

    public const OPEN = 'open';

    public const USED = 'used';

    public const EXPIRED = 'expired';

    public function __construct(
        public readonly string $status,
        public readonly ?CarbonImmutable $endsAt = null,
        public readonly ?string $listingId = null,
    ) {}

    public static function none(): self
    {
        return new self(self::NONE);
    }

    public static function for(Order $order, ?OrderCollection $collection, ?Listing $relisted, ?CarbonImmutable $now = null): self
    {
        $until = $collection?->free_relist_until;

        if ($until === null || $collection->collected_at === null) {
            return self::none();
        }

        // A sale of a free relist gives no offer (spec 018 Q3); the handover stores none, this is defence in depth.
        if ($order->listing?->isFreeRelist()) {
            return self::none();
        }

        if ($relisted !== null) {
            return new self(self::USED, $until, $relisted->listing_id);
        }

        return ($now ?? CarbonImmutable::now())->greaterThan($until)
            ? new self(self::EXPIRED, $until)
            : new self(self::OPEN, $until);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** @return array{status: string, ends_at: string|null, listing_id: string|null} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'ends_at' => $this->endsAt?->toIso8601String(),
            'listing_id' => $this->listingId,
        ];
    }
}
