<?php

namespace App\Actions\Withdrawals\Concerns;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\PauseTrigger;
use App\Enums\PayoutAccountChangeKind;
use App\Enums\PayoutAccountState;
use App\Enums\PayoutEvent;
use App\Enums\WithdrawalState;
use App\Exceptions\DomainApiException;
use App\Jobs\NotifyCustomerJob;
use App\Models\PayoutAccount;
use App\Models\PayoutAccountChange;
use App\Models\Withdrawal;
use App\Models\WithdrawalPause;
use App\Notifications\PayoutNotification;
use App\Support\RequestContext;
use App\Support\Withdrawals\WithdrawalLedger;
use App\Support\Withdrawals\WithdrawalSafetyStop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The shared work of payout accounts and withdrawals (spec 013 research R6,
 * R7). Everything runs inside the caller's transaction:
 *
 *  - Lock order, on every path: the customer's payout accounts, then the
 *    customer's withdrawals, then (inside the money service) the wallet
 *    accounts. A path that starts from a withdrawal id reads its customer
 *    unlocked (the guard freezes it), locks the accounts, locks the
 *    withdrawal, then re-checks its state (analysis I3).
 *  - `moveWithdrawal()` is the only code that changes `withdrawal.state`.
 *  - `changeAccount()` is the only code that changes `payout_account.state`
 *    or `is_in_use`, and it writes the history row naming the actor.
 *  - Messages are collected and sent only after commit (a rollback sends nothing).
 */
trait WorksWithdrawals
{
    /** @var list<array{0: string, 1: PayoutNotification}> */
    private array $payoutOutbox = [];

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Payout accounts and withdrawals change inside a transaction.');
        }
    }

    /** @return Collection<int, PayoutAccount> the customer's accounts, locked, keyed by id */
    private function lockAccounts(string $customerId): Collection
    {
        $this->requireTransaction();

        return PayoutAccount::query()->where('customer_id', $customerId)
            ->orderBy('payout_account_id')->lockForUpdate()->get()->keyBy('payout_account_id');
    }

    /** @return Collection<int, Withdrawal> the customer's open (not released) withdrawals, locked */
    private function lockOpenWithdrawals(string $customerId): Collection
    {
        return Withdrawal::query()->where('customer_id', $customerId)
            ->whereIn('state', WithdrawalState::openValues())
            ->orderBy('withdrawal_id')->lockForUpdate()->get();
    }

    /**
     * A withdrawal for an action, in the lock order of R6: its customer read
     * unlocked, then the customer's accounts, then the withdrawal itself.
     * 404 when the current scope cannot see it.
     *
     * @return array{0: Withdrawal, 1: Collection<int, PayoutAccount>}
     */
    private function lockWithdrawal(string $withdrawalId): array
    {
        $this->requireTransaction();
        $customerId = Withdrawal::query()->whereKey($withdrawalId)->valueOrFail('customer_id');
        $accounts = $this->lockAccounts($customerId);
        $withdrawal = Withdrawal::query()->whereKey($withdrawalId)->lockForUpdate()->firstOrFail();

        return [$withdrawal, $accounts];
    }

    /**
     * Move a locked withdrawal (the database repeats the check — DH007).
     *
     * @param  array<string, mixed>  $set
     */
    private function moveWithdrawal(Withdrawal $withdrawal, WithdrawalState $to, array $set = []): Withdrawal
    {
        if (! $withdrawal->state->canMoveTo($to)) {
            throw DomainApiException::illegalWithdrawalTransition();
        }

        $withdrawal->forceFill($set);
        $withdrawal->state = $to;
        if (! $to->isOpen()) {
            $withdrawal->ended_at = now();
        }
        $withdrawal->save();

        return $withdrawal;
    }

    /**
     * Change an account's state and/or in-use flag and record it. Exactly one actor.
     *
     * @param  array<string, mixed>  $set
     */
    private function changeAccount(
        PayoutAccount $account,
        PayoutAccountChangeKind $kind,
        ?string $actorCustomerId,
        ?string $actorStaffId,
        ?PayoutAccountState $to = null,
        array $set = [],
        ?string $pauseId = null,
    ): PayoutAccount {
        if ($to !== null && $to !== $account->state) {
            if (! $account->state->canMoveTo($to)) {
                throw DomainApiException::illegalPayoutAccountTransition();
            }
            $account->state = $to;
        }

        $account->forceFill($set);
        $account->save();

        PayoutAccountChange::query()->create([
            'customer_id' => $account->customer_id,
            'payout_account_id' => $account->payout_account_id,
            'kind' => $kind,
            'actor_customer_id' => $actorCustomerId,
            'actor_staff_id' => $actorStaffId,
            'pause_id' => $pauseId,
        ]);

        return $account;
    }

    /**
     * A removing account whose last open withdrawal just ended becomes removed
     * (and stops being in use) in the same transaction (spec 013 US3).
     *
     * @param  Collection<int, PayoutAccount>  $accounts  the customer's locked accounts
     */
    private function finishRemoval(Collection $accounts, string $accountId, ?string $actorCustomerId, ?string $actorStaffId): void
    {
        $account = $accounts->get($accountId);
        if ($account === null || $account->state !== PayoutAccountState::REMOVING) {
            return;
        }

        $stillOpen = Withdrawal::query()->where('payout_account_id', $accountId)
            ->whereIn('state', WithdrawalState::openValues())->exists();
        if ($stillOpen) {
            return;
        }

        $this->changeAccount($account, PayoutAccountChangeKind::REMOVED, $actorCustomerId, $actorStaffId,
            PayoutAccountState::REMOVED, ['is_in_use' => false, 'removed_at' => now()]);
        $this->tellPayout($account->customer_id, new PayoutNotification(PayoutEvent::ACCOUNT_REMOVED, account: $account->shortLabel()));
    }

    /**
     * Make `$account` the one in use (spec 013 FR-005). Unless this is the
     * customer's first account ever in use: cancel every open withdrawal
     * (money back) and open a pause for the setting's hours (none at 0).
     *
     * @param  Collection<int, PayoutAccount>  $accounts  the customer's locked accounts
     * @return array{pause: ?WithdrawalPause, cancelled: list<string>}
     */
    private function makeInUse(
        Collection $accounts,
        PayoutAccount $account,
        ?string $actorCustomerId,
        ?string $actorStaffId,
        ?RequestContext $ctx,
    ): array {
        $customerId = $account->customer_id;
        $firstEver = ! PayoutAccountChange::query()->where('customer_id', $customerId)
            ->where('kind', PayoutAccountChangeKind::IN_USE->value)->exists();

        $pause = null;
        $cancelled = [];
        $previous = $accounts->first(fn (PayoutAccount $a) => $a->is_in_use && $a->payout_account_id !== $account->payout_account_id);

        if (! $firstEver) {
            // The accounts are already locked by the caller (R6); shared with spec 017's contact changes.
            ['pause' => $pause, 'cancelled' => $cancelled] = app(WithdrawalSafetyStop::class)->apply(
                $customerId, PauseTrigger::PAYOUT_ACCOUNT, 'payout account changed',
                accountId: $account->payout_account_id, actorCustomerId: $actorCustomerId, actorStaffId: $actorStaffId,
            );
        }

        if ($previous !== null) {
            $previous->forceFill(['is_in_use' => false])->save();
        }

        $this->changeAccount($account, PayoutAccountChangeKind::IN_USE, $actorCustomerId, $actorStaffId,
            set: ['is_in_use' => true], pauseId: $pause?->pause_id);

        // The previous account, if it was being removed, has no open withdrawal any more.
        if ($previous !== null) {
            $this->finishRemoval($accounts, $previous->payout_account_id, $actorCustomerId, $actorStaffId);
        }

        app(RecordAuditLogAction::class)->execute(
            AuditEvent::PAYOUT_ACCOUNT_IN_USE_CHANGED, 'success',
            [
                'payout_account_id' => $account->payout_account_id,
                'account' => $account->shortLabel(),
                'previous_account_id' => $previous?->payout_account_id,
                'first_in_use' => $firstEver,
                'pause_until' => $pause?->pause_until?->toIso8601String(),
                'cancelled_withdrawals' => $cancelled,
            ],
            'payout_account', $account->payout_account_id, $ctx,
            actorCustomerId: $actorCustomerId, actorStaffId: $actorStaffId,
        );

        $this->tellPayout($customerId, new PayoutNotification(PayoutEvent::ACCOUNT_IN_USE,
            account: $account->shortLabel(), until: $pause?->pause_until?->toIso8601String()));
        if ($cancelled !== []) {
            $this->tellPayout($customerId, new PayoutNotification(PayoutEvent::WITHDRAWALS_CANCELLED_BY_CHANGE, cancelledCount: count($cancelled)));
        }

        return ['pause' => $pause, 'cancelled' => $cancelled];
    }

    /**
     * The gates of a new withdrawal (spec 013 FR-009), checked under the
     * account locks: no open pause, the account is the customer's, active and
     * in use (a `removing` one takes no new withdrawal), and the money is
     * available. The email confirmation is checked by the caller.
     *
     * @param  Collection<int, PayoutAccount>  $accounts  the customer's locked accounts
     */
    private function assertCanWithdraw(string $customerId, Collection $accounts, string $accountId, string $amount): PayoutAccount
    {
        $pause = WithdrawalPause::openFor($customerId);
        if ($pause !== null) {
            throw DomainApiException::withdrawalsPaused($pause->pause_until->toIso8601String());
        }

        $account = $accounts->get($accountId);
        if ($account === null || $account->state !== PayoutAccountState::ACTIVE || ! $account->is_in_use) {
            throw DomainApiException::payoutAccountNotActive();
        }

        $ledger = app(WithdrawalLedger::class);
        if (bccomp($ledger->available($customerId), $amount, 4) < 0) {
            throw $ledger->insufficient($customerId, $amount);
        }

        return $account;
    }

    private function tellPayout(string $customerId, PayoutNotification $notification): void
    {
        $this->payoutOutbox[] = [$customerId, $notification];
    }

    /** Send what was collected once the transaction commits (call inside it). */
    private function flushPayoutOutbox(): void
    {
        $outbox = $this->payoutOutbox;
        $this->payoutOutbox = [];

        DB::afterCommit(function () use ($outbox) {
            foreach ($outbox as [$customerId, $notification]) {
                NotifyCustomerJob::dispatch($customerId, $notification);
            }
        });
    }
}
