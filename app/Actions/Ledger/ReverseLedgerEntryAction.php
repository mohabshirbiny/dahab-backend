<?php

namespace App\Actions\Ledger;

use App\Enums\LedgerEventKind;
use App\Exceptions\DomainApiException;
use App\Models\LedgerTransaction;
use App\Support\DatabaseActor;
use App\Support\Ledger\LedgerEntry;
use App\Support\Ledger\LedgerLine;
use InvalidArgumentException;

/**
 * The only correction the ledger allows (spec 008 FR-010): a new entry of
 * kind `reversal` that negates every line of the original, points to it,
 * carries the reason as its memo and a named staff actor, and copies the
 * references. The original is never touched. An entry is reversed at most
 * once — checked under a lock on the original here, with the partial unique
 * index `one_reversal_per_txn` as the backstop. Runs inside the caller's
 * transaction, like PostLedgerEntryAction.
 */
final class ReverseLedgerEntryAction
{
    public function __construct(private readonly PostLedgerEntryAction $post) {}

    public function handle(string $ledgerTxnId, string $staffId, string $reason): LedgerTransaction
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('A reversal needs a reason of 1 to 1000 characters.');
        }

        $original = DatabaseActor::ledger(function () use ($ledgerTxnId) {
            $original = LedgerTransaction::query()->whereKey($ledgerTxnId)->lockForUpdate()->firstOrFail();

            if (LedgerTransaction::query()->where('reverses_txn_id', $ledgerTxnId)->exists()) {
                throw DomainApiException::ledgerAlreadyReversed();
            }

            return $original->load('postings');
        });

        return $this->post->handle(new LedgerEntry(
            LedgerEventKind::REVERSAL,
            $original->postings->map(fn ($p) => new LedgerLine($p->account_id, bcmul((string) $p->amount, '-1', 4)))->all(),
            actorStaffId: $staffId,
            memo: $reason,
            listingId: $original->listing_id,
            orderId: $original->order_id,
            buyRequestId: $original->buy_request_id,
            withdrawalId: $original->withdrawal_id,
            reversesTxnId: $original->ledger_txn_id,
        ));
    }
}
