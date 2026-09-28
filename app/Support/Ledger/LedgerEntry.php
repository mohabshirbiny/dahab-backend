<?php

namespace App\Support\Ledger;

use App\Enums\LedgerEventKind;
use InvalidArgumentException;

/**
 * One business event to post (spec 008 FR-005, data-model "Posting
 * contract"): an event kind, a named actor, at least two lines that sum to
 * exactly zero, and optional references. Validated on construction so an
 * invalid entry never reaches the database.
 */
final readonly class LedgerEntry
{
    /** @var list<LedgerLine> */
    public array $lines;

    /**
     * @param  list<LedgerLine>  $lines
     */
    public function __construct(
        public LedgerEventKind $kind,
        array $lines,
        public ?string $actorCustomerId = null,
        public ?string $actorStaffId = null,
        public ?string $memo = null,
        public ?string $listingId = null,
        public ?string $orderId = null,
        public ?string $buyRequestId = null,
        public ?string $withdrawalId = null,
        public ?string $reversesTxnId = null,
    ) {
        if ($actorCustomerId === null && $actorStaffId === null) {
            throw new InvalidArgumentException('A ledger entry needs a named actor (a customer or a staff member).');
        }

        if (count($lines) < 2) {
            throw new InvalidArgumentException('A ledger entry needs at least two lines.');
        }

        $sum = '0';
        foreach ($lines as $line) {
            if (! $line instanceof LedgerLine) {
                throw new InvalidArgumentException('Ledger lines must be LedgerLine instances.');
            }
            $sum = bcadd($sum, $line->amount, 4);
        }

        if (bccomp($sum, '0', 4) !== 0) {
            throw new InvalidArgumentException("A ledger entry must sum to zero; this one is off by {$sum}.");
        }

        if ($memo !== null && mb_strlen($memo) > 1000) {
            throw new InvalidArgumentException('A ledger memo is at most 1000 characters.');
        }

        $this->lines = array_values($lines);
    }

    /**
     * Net amount per account (an entry may touch one account twice).
     *
     * @return array<string, string>
     */
    public function netByAccount(): array
    {
        $net = [];
        foreach ($this->lines as $line) {
            $net[$line->accountId] = bcadd($net[$line->accountId] ?? '0', $line->amount, 4);
        }

        return $net;
    }
}
