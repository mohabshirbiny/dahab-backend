<?php

namespace App\Actions\Promo;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\CreditStatus;
use App\Models\CommissionCredit;
use App\Models\CommissionCreditEvent;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\RequestContext;

/**
 * Void the commission credits bound to a listing (spec 023 research R6, T037): the piece left the
 * market without a sale (withdrawn, rejected, taken down) or its code was removed or replaced.
 * One `voided` event each, naming the actor and the reason. The `listing_promo` row is left to the
 * caller: a relisted piece keeps its history, and the seller applies a code again.
 */
final class VoidListingCreditsAction
{
    public function __construct(private readonly RecordAuditLogAction $audit) {}

    /** Called from `MovesListing` when a listing leaves the market without a sale. */
    public function onLeftMarket(Listing $listing, string $reason, ?Customer $byCustomer, ?Staff $byStaff): void
    {
        // Credit rows are writable in the `promo` scope (the seller's own) or by staff; an elevated
        // caller is never narrowed.
        DatabaseActor::promo(fn () => $this->forListing($listing, $reason, $byCustomer, $byStaff, null));
    }

    public function forListing(Listing $listing, string $reason, ?Customer $byCustomer, ?Staff $byStaff, ?RequestContext $ctx): int
    {
        $voided = 0;

        CommissionCredit::query()->where('listing_id', $listing->listing_id)
            ->where('status', CreditStatus::ACTIVE->value)->orderBy('credit_id')->lockForUpdate()->get()
            ->each(function (CommissionCredit $credit) use ($reason, $byCustomer, $byStaff, $ctx, &$voided) {
                $credit->status = CreditStatus::VOIDED;
                $credit->ended_at = now();
                $credit->save();

                CommissionCreditEvent::query()->create([
                    'credit_id' => $credit->credit_id,
                    'kind' => 'voided',
                    'amount_left_after_egp' => $credit->amount_left_egp,
                    'uses_left_after' => $credit->uses_left,
                    'reason' => $reason,
                    'actor_customer_id' => $byCustomer?->customer_id,
                    'actor_staff_id' => $byCustomer === null ? $byStaff?->staff_id : null,
                ]);

                $this->audit->execute(
                    AuditEvent::COMMISSION_CREDIT_VOIDED,
                    'success',
                    ['credit_id' => $credit->credit_id, 'listing_id' => $credit->listing_id, 'reason' => $reason],
                    'commission_credit',
                    $credit->credit_id,
                    $ctx,
                    actorCustomerId: $byCustomer?->customer_id,
                    actorStaffId: $byCustomer === null ? $byStaff?->staff_id : null,
                );
                $voided++;
            });

        return $voided;
    }
}
