<?php

namespace App\Support\Withdrawals;

use App\Enums\WithdrawalState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters of the staff Withdrawals list and its export (spec 013 FR-012):
 * states (default requested + under review), held only, Cairo date range on
 * the request time, a customer, and a search on the display reference, the
 * E.164 phone or the name (the spec 007 search).
 */
final readonly class WithdrawalListQuery
{
    /** @param  list<WithdrawalState>  $states */
    public function __construct(
        public array $states,
        public bool $heldOnly,
        public ?string $from,
        public ?string $to,
        public ?string $q,
        public ?string $customerId,
    ) {}

    public function apply(Builder $query): Builder
    {
        $query->whereIn('withdrawal.state', array_map(fn (WithdrawalState $s) => $s->value, $this->states));

        if ($this->heldOnly) {
            $query->where('withdrawal.state', WithdrawalState::UNDER_REVIEW->value)->whereNotNull('withdrawal.held_at');
        }
        if ($this->from !== null) {
            $query->where('withdrawal.requested_at', '>=', CarbonImmutable::parse($this->from, 'Africa/Cairo')->startOfDay());
        }
        if ($this->to !== null) {
            $query->where('withdrawal.requested_at', '<', CarbonImmutable::parse($this->to, 'Africa/Cairo')->addDay()->startOfDay());
        }
        if ($this->customerId !== null) {
            $query->where('withdrawal.customer_id', $this->customerId);
        }
        if (filled($this->q)) {
            $term = trim((string) $this->q);
            $query->whereHas('customer', fn (Builder $c) => $c->where(fn (Builder $w) => $w
                ->where('display_ref', $term)
                ->orWhere('phone', $term)
                ->orWhere('full_name', 'ilike', '%'.addcslashes($term, '%_\\').'%')));
        }

        return $query;
    }

    /** @return array<string, mixed> for the export's audit row */
    public function toArray(): array
    {
        return [
            'states' => array_map(fn (WithdrawalState $s) => $s->value, $this->states),
            'held_only' => $this->heldOnly,
            'from' => $this->from,
            'to' => $this->to,
            'q' => $this->q,
            'customer_id' => $this->customerId,
        ];
    }
}
