<?php

namespace App\Actions\Payouts\Staff;

use App\Enums\PayoutAccountState;
use App\Models\PayoutAccount;
use App\Support\Listings\ListingCursor;
use App\Support\Withdrawals\KeysetPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Payout accounts to check (spec 013 FR-003): oldest first, keyset pages,
 * with the customer's verified ID name beside the holder name. `q` matches
 * the display reference, the E.164 phone or the name (spec 007 search).
 */
final class ListPayoutAccountsForReviewAction
{
    /** @return array{rows: Collection<int, PayoutAccount>, next_cursor: string|null, waiting: int} */
    public function handle(PayoutAccountState $state, ?string $q, ?ListingCursor $cursor, int $perPage): array
    {
        $query = PayoutAccount::query()
            ->with(['customer', 'checkedBy'])
            ->where('payout_account.state', $state->value)
            ->when(filled($q), function (Builder $query) use ($q) {
                $term = trim((string) $q);
                $query->whereHas('customer', fn (Builder $c) => $c->where(fn (Builder $w) => $w
                    ->where('display_ref', $term)
                    ->orWhere('phone', $term)
                    ->orWhere('full_name', 'ilike', '%'.addcslashes($term, '%_\\').'%')));
            });

        $page = KeysetPage::byTime($query, 'payout_account', 'created_at', 'payout_account_id',
            $state !== PayoutAccountState::PENDING_REVIEW, $cursor, $perPage);

        return $page + ['waiting' => PayoutAccount::query()->where('state', PayoutAccountState::PENDING_REVIEW->value)->count()];
    }
}
