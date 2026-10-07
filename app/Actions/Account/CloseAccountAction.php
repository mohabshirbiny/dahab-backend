<?php

namespace App\Actions\Account;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Actions\Listings\Concerns\MovesListing;
use App\Enums\AccountEvent;
use App\Enums\AccountKind;
use App\Enums\AuditEvent;
use App\Enums\CloseReason;
use App\Enums\CustomerStatus;
use App\Enums\ListingState;
use App\Exceptions\DomainApiException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Listing;
use App\Notifications\AccountNotification;
use App\Services\ContactChangeChallengeStore;
use App\Support\Account\CloseBlockers;
use App\Support\Account\CustomerSessions;
use App\Support\DatabaseActor;
use App\Support\RequestContext;
use App\Support\Withdrawals\WithdrawalSafetyStop;
use Illuminate\Support\Facades\DB;

/**
 * Close my account (spec 017 FR-050, FR-051, research R8). Refused while
 * anything is in progress or any money is left. Otherwise, in one
 * transaction: the pieces not in a sale leave the market (`withdrawn`), the
 * customer is `closed`, pending contact changes die, every session ends and
 * every trusted device is forgotten. Nothing is deleted or anonymised.
 *
 * Lock order: payout accounts, the customer row, the seller's listings, then the
 * wallet accounts — the money service locks a listing before its accounts, so
 * a racing buy request, credit or withdrawal either commits first (and blocks
 * the close) or waits and is refused afterwards (guard DH013).
 */
final class CloseAccountAction
{
    use MovesListing;

    /** The pieces closing takes off the market or out of review. */
    private const WITHDRAWN_ON_CLOSE = [
        ListingState::DRAFT, ListingState::IN_REVIEW, ListingState::CHANGES_REQUESTED, ListingState::LIVE, ListingState::SUSPENDED_HOLD,
    ];

    public function __construct(
        private readonly CloseBlockers $blockers,
        private readonly WithdrawalSafetyStop $safetyStop,
        private readonly CustomerSessions $sessions,
        private readonly ContactChangeChallengeStore $challenges,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /** @return list<array{code: string, count: int}> */
    public function check(Customer $customer): array
    {
        return $this->blockers->for($customer->customer_id);
    }

    public function close(Customer $customer, CloseReason $reason, ?string $note, ?RequestContext $ctx = null): Customer
    {
        $closed = DB::transaction(function () use ($customer, $reason, $note, $ctx) {
            $this->safetyStop->lockPayoutAccounts($customer->customer_id);
            $locked = Customer::query()->whereKey($customer->customer_id)->lockForUpdate()->firstOrFail();
            if ($locked->status === CustomerStatus::CLOSED) {
                return $locked;
            }

            $listings = Listing::query()->where('seller_id', $locked->customer_id)->orderBy('listing_id')->lockForUpdate()->get();
            DatabaseActor::ledger(fn () => Account::query()->whereIn('account_id', [
                Account::forCustomerKind($locked->customer_id, AccountKind::CUST_AVAILABLE),
                Account::forCustomerKind($locked->customer_id, AccountKind::CUST_HELD),
            ])->orderBy('account_id')->lockForUpdate()->get());

            $blockers = $this->blockers->for($locked->customer_id);
            if ($blockers !== []) {
                throw DomainApiException::accountHasOpenItems($blockers);
            }

            $withdrawn = 0;
            foreach ($listings as $listing) {
                if (in_array($listing->state, self::WITHDRAWN_ON_CLOSE, true)) {
                    $this->moveListing($listing, ListingState::WITHDRAWN, $locked, null, 'account closed');
                    $withdrawn++;
                }
            }

            $before = $locked->status;
            $locked->forceFill([
                'status' => CustomerStatus::CLOSED,
                'closed_at' => now(),
                'closed_reason' => $reason,
                'closed_note' => $reason === CloseReason::OTHER ? $note : null,
                // A suspension ends with the account; its reason and dates stay on the row and in the log.
                'status_before_suspension' => null,
            ])->save();

            DB::table('one_time_token')->where('actor_customer_id', $locked->customer_id)
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $this->sessions->endOthers($locked, null);
            $this->sessions->forgetOthers($locked, null);

            $this->audit->execute(AuditEvent::CUSTOMER_ACCOUNT_CLOSED, 'success', [
                'reason' => $reason->value,
                'listings_withdrawn' => $withdrawn,
            ], 'customer', $locked->customer_id, $ctx, actorCustomerId: $locked->customer_id,
                before: ['status' => $before->value], reason: $note);

            $notice = new AccountNotification(AccountEvent::ACCOUNT_CLOSED, $locked->preferred_lang ?? 'ar');
            DB::afterCommit(fn () => $locked->notify($notice));

            return $locked;
        });

        $this->challenges->forgetFor($customer->customer_id);

        return $closed;
    }
}
