<?php

namespace App\Support\Finance;

use App\Enums\CompensationReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters of the Compensation list and its export (spec 015 FR-001): a
 * Cairo date range on the payment time (default the last 30 days), the reason,
 * the payer and the customer.
 */
final readonly class CompensationListQuery
{
    public function __construct(
        public string $from,
        public string $to,
        public ?CompensationReason $reason,
        public ?string $paidBy,
        public ?string $customerId,
    ) {}

    public function apply(Builder $query): Builder
    {
        $query->where('compensation.paid_at', '>=', CarbonImmutable::parse($this->from, 'Africa/Cairo')->startOfDay())
            ->where('compensation.paid_at', '<', CarbonImmutable::parse($this->to, 'Africa/Cairo')->addDay()->startOfDay());

        if ($this->reason !== null) {
            $query->where('compensation.reason', $this->reason->value);
        }
        if ($this->paidBy !== null) {
            $query->where('compensation.paid_by', $this->paidBy);
        }
        if ($this->customerId !== null) {
            $query->where('compensation.customer_id', $this->customerId);
        }

        return $query;
    }

    /** @return array<string, mixed> for the export's audit row */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'reason' => $this->reason?->value, 'paid_by' => $this->paidBy, 'customer_id' => $this->customerId];
    }
}
