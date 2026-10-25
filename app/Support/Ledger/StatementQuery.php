<?php

namespace App\Support\Ledger;

use InvalidArgumentException;

/**
 * What a Wallet statement is about (spec 008 FR-016, contracts/wallet-api.md):
 *
 * | view      | rows are entries touching           | running balance on          |
 * |-----------|-------------------------------------|-----------------------------|
 * | customer  | the customer's available or held    | available (holds are "out") |
 * | customers | any customer account                | available + held (owed)     |
 * | dahab     | commission or spread, or an advance | commission + spread         |
 * | equity    | external_equity (Dahab's own money) | external_equity             |
 *
 * The Dahab view also lists the first-sale advance (spec 023) leaving `external_equity`
 * (`first_sale_payout`) and coming back at the settlement (`balance_payment`), in its own
 * figure: Dahab's money fronted to a seller, never part of what Dahab earned.
 *
 * The equity view is Dahab's own money: capital paid in and supplier refunds in; advances,
 * compensation, wallet adjustments, expenses, bank charges and profit taken out; advances
 * recovered back in. Its balance is SUM(external_equity) — positive while the owners have put
 * in more than was spent from it.
 *
 * `from` / `to` are Cairo calendar days, inclusive.
 */
final readonly class StatementQuery
{
    public const VIEWS = ['customer', 'customers', 'dahab', 'equity'];

    public const GRAINS = ['each', 'day', 'month'];

    public function __construct(
        public string $view,
        public string $from,
        public string $to,
        public string $grain = 'each',
        public ?string $customerId = null,
    ) {
        if (! in_array($view, self::VIEWS, true) || ! in_array($grain, self::GRAINS, true)) {
            throw new InvalidArgumentException('Unknown statement view or grain.');
        }

        if (($view === 'customer') !== ($customerId !== null)) {
            throw new InvalidArgumentException('A customer statement needs exactly one customer.');
        }
    }

    /**
     * SQL predicates over `a` (account) for the lines that make a row appear,
     * and for the lines that move the running balance. `advance` is over `a`
     * and `pt` (the period's transaction): the first-sale advance lines, which
     * also make a row appear but never move the balance. Built from constants
     * only; the customer id is a bound parameter.
     *
     * @return array{touch: string, run: string, advance: string, bindings: list<string>}
     */
    public function predicates(): array
    {
        return match ($this->view) {
            'customer' => [
                'touch' => 'a.customer_id = ?',
                'run' => "a.customer_id = ? AND a.kind = 'cust_available'",
                'advance' => 'FALSE',
                'bindings' => [$this->customerId],
            ],
            'customers' => [
                'touch' => "a.kind IN ('cust_available','cust_held')",
                'run' => "a.kind IN ('cust_available','cust_held')",
                'advance' => 'FALSE',
                'bindings' => [],
            ],
            'dahab' => [
                // igi_certificates makes a row appear (the seller's certificate fee) but never moves the balance.
                'touch' => "a.kind IN ('dahab_commission','dahab_spread','igi_certificates')",
                'run' => "a.kind IN ('dahab_commission','dahab_spread')",
                'advance' => "a.kind = 'external_equity' AND pt.event_kind::text IN ('first_sale_payout','balance_payment')",
                'bindings' => [],
            ],
            'equity' => [
                'touch' => "a.kind = 'external_equity'",
                'run' => "a.kind = 'external_equity'",
                'advance' => 'FALSE',
                'bindings' => [],
            ],
        };
    }

    /** @return array{view: string, from: string, to: string, grain: string} */
    public function toArray(): array
    {
        return ['view' => $this->view, 'from' => $this->from, 'to' => $this->to, 'grain' => $this->grain];
    }
}
