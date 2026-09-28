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
 * | dahab     | commission or spread                | commission + spread         |
 *
 * `from` / `to` are Cairo calendar days, inclusive.
 */
final readonly class StatementQuery
{
    public const VIEWS = ['customer', 'customers', 'dahab'];

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
     * and for the lines that move the running balance. Built from constants
     * only; the customer id is a bound parameter.
     *
     * @return array{touch: string, run: string, bindings: list<string>}
     */
    public function predicates(): array
    {
        return match ($this->view) {
            'customer' => [
                'touch' => 'a.customer_id = ?',
                'run' => "a.customer_id = ? AND a.kind = 'cust_available'",
                'bindings' => [$this->customerId],
            ],
            'customers' => [
                'touch' => "a.kind IN ('cust_available','cust_held')",
                'run' => "a.kind IN ('cust_available','cust_held')",
                'bindings' => [],
            ],
            'dahab' => [
                'touch' => "a.kind IN ('dahab_commission','dahab_spread')",
                'run' => "a.kind IN ('dahab_commission','dahab_spread')",
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
