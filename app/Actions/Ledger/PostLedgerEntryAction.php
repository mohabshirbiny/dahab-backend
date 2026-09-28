<?php

namespace App\Actions\Ledger;

use App\Exceptions\DomainApiException;
use App\Models\LedgerTransaction;
use App\Support\DatabaseActor;
use App\Support\Ledger\LedgerEntry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The money service (spec 008 FR-009, Part 3 §0): the only code that writes
 * the ledger. It runs inside the caller's transaction, so the entry commits
 * or rolls back with the caller's audit row and state change.
 *
 * Order of work (research R2, R5):
 *  1. the entry was validated when it was built (LedgerEntry);
 *  2. in the `ledger` RLS scope, load the accounts and lock the customer
 *     ones FOR UPDATE in account_id order — never the internal singletons;
 *  3. refuse with insufficient_funds if a customer balance would go below
 *     zero (the deferred trigger is the backstop);
 *  4. insert the transaction and its lines.
 */
final class PostLedgerEntryAction
{
    public function handle(LedgerEntry $entry): LedgerTransaction
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('PostLedgerEntryAction must run inside the caller\'s database transaction.');
        }

        return DatabaseActor::ledger(function () use ($entry) {
            $net = $entry->netByAccount();
            $ids = array_keys($net);

            $accounts = DB::table('account')->whereIn('account_id', $ids)->get(['account_id', 'kind', 'customer_id'])->keyBy('account_id');

            if ($accounts->count() !== count($ids)) {
                throw new InvalidArgumentException('A ledger line names an account that does not exist.');
            }

            $customerAccounts = $accounts->whereNotNull('customer_id')->keys()->sort()->values()->all();

            if ($customerAccounts !== []) {
                $locked = DB::table('account')->whereIn('account_id', $customerAccounts)->orderBy('account_id')->lockForUpdate()->pluck('account_id');

                // Row-level security can make FOR UPDATE match nothing without an
                // error (it needs the lock-only UPDATE policy). Never post unlocked.
                if ($locked->count() !== count($customerAccounts)) {
                    throw new LogicException('Could not lock the customer accounts of a ledger entry.');
                }

                $balances = DB::table('ledger_posting')
                    ->whereIn('account_id', $customerAccounts)
                    ->groupBy('account_id')
                    ->selectRaw('account_id, SUM(amount)::text AS balance')
                    ->pluck('balance', 'account_id');

                foreach ($customerAccounts as $accountId) {
                    if (bccomp(bcadd((string) ($balances[$accountId] ?? '0'), $net[$accountId], 4), '0', 4) < 0) {
                        throw DomainApiException::insufficientFunds();
                    }
                }
            }

            $txn = LedgerTransaction::query()->create([
                'ledger_txn_id' => (string) DB::selectOne('SELECT gen_random_uuid() AS id')->id,
                'event_kind' => $entry->kind,
                'customer_id' => $entry->actorCustomerId,
                'staff_id' => $entry->actorStaffId,
                'memo' => $entry->memo,
                'listing_id' => $entry->listingId,
                'order_id' => $entry->orderId,
                'buy_request_id' => $entry->buyRequestId,
                'withdrawal_id' => $entry->withdrawalId,
                'reverses_txn_id' => $entry->reversesTxnId,
            ]);

            DB::table('ledger_posting')->insert(array_map(fn ($line) => [
                'ledger_txn_id' => $txn->ledger_txn_id,
                'account_id' => $line->accountId,
                'amount' => $line->amount,
            ], $entry->lines));

            return $txn;
        });
    }
}
