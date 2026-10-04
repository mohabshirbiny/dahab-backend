<?php

namespace App\Actions\Finance;

use App\Models\WalletAdjustment;
use App\Support\Listings\ListingCursor;
use App\Support\Withdrawals\KeysetPage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** The wallet adjustments, newest first, keyset pages (spec 015 FR-006). */
final class ListWalletAdjustmentsAction
{
    public const RELATIONS = ['customer', 'adjuster'];

    /** @return array{rows: Collection<int, WalletAdjustment>, next_cursor: string|null} */
    public function handle(string $from, string $to, ?string $customerId, ?ListingCursor $cursor, int $perPage): array
    {
        $query = WalletAdjustment::query()->with(self::RELATIONS)
            ->where('wallet_adjustment.adjusted_at', '>=', CarbonImmutable::parse($from, 'Africa/Cairo')->startOfDay())
            ->where('wallet_adjustment.adjusted_at', '<', CarbonImmutable::parse($to, 'Africa/Cairo')->addDay()->startOfDay())
            ->when($customerId !== null, fn ($q) => $q->where('wallet_adjustment.customer_id', $customerId));

        return KeysetPage::byTime($query, 'wallet_adjustment', 'adjusted_at', 'adjustment_id', true, $cursor, $perPage);
    }
}
