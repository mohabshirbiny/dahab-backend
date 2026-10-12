<?php

namespace App\Support\Promo;

use App\Enums\CreditStatus;
use App\Models\CommissionCredit;
use App\Support\Pricing\CreditCandidate;
use Illuminate\Support\Collection;

/**
 * The credits a seller may spend on one sale (spec 023 research R2): the seller's own, active,
 * not past their expiry, and either bound to this listing or unbound (the inviter's pool).
 * One query, used by the settlement, the advance's provisional proceeds and the sell-form preview,
 * so the three agree.
 */
final class CommissionCredits
{
    /**
     * @return Collection<int, CommissionCredit>
     */
    public function usable(string $sellerId, ?string $listingId, bool $lock = false): Collection
    {
        $query = CommissionCredit::query()
            ->where('customer_id', $sellerId)
            ->where('status', CreditStatus::ACTIVE->value)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function ($q) use ($listingId) {
                $q->whereNull('listing_id');
                if ($listingId !== null) {
                    $q->orWhere('listing_id', $listingId);
                }
            })
            ->orderBy('credit_id');

        if ($lock) {
            // The settlement locks the credits before reading them, in a fixed order, so two
            // payments that could use the same credit queue instead of both spending it.
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, CommissionCredit>  $credits
     * @return list<CreditCandidate>
     */
    public function candidates(Collection $credits): array
    {
        return $credits->map(fn (CommissionCredit $c) => new CreditCandidate(
            id: $c->credit_id,
            kind: $c->kind,
            source: $c->source,
            percentOff: $c->percent_off === null ? null : (string) $c->percent_off,
            amountLeft: $c->amount_left_egp === null ? null : (string) $c->amount_left_egp,
            expiresAt: $c->expires_at?->toIso8601String(),
            grantedAt: $c->granted_at->toIso8601String(),
        ))->values()->all();
    }
}
