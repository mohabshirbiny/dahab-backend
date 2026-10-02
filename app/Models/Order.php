<?php

namespace App\Models;

use App\Enums\OrderState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One accepted buyer's purchase (schema §9, table `"order"`). Spec 011
 * creates it when the seller accepts the head of the queue and lets staff
 * cancel it (`cancelled_staff`); spec 012 moves it through delivery,
 * inspection, payment and collection. Moves follow `order_transition`
 * (trg_order_transition, SQLSTATE DH006) and each one is recorded in
 * `order_state_change` (trg_order_change_recorded).
 *
 * @property string $order_id
 * @property string $order_ref
 * @property string $listing_id
 * @property string $buy_request_id
 * @property string $seller_id
 * @property string $buyer_id
 * @property OrderState $state
 * @property int $branch_id
 * @property string $accepted_by
 * @property CarbonImmutable $accepted_at
 * @property CarbonImmutable $reach_branch_deadline
 * @property string $locked_total_price
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property string|null $locked_seller_unit_rate
 * @property CarbonImmutable|null $decision_due_deadline
 * @property string|null $proposed_price
 * @property string|null $proposed_by
 * @property CarbonImmutable|null $proposed_at
 * @property CarbonImmutable|null $balance_due_deadline
 * @property CarbonImmutable|null $collect_deadline
 * @property CarbonImmutable|null $completed_at
 * @property string|null $final_weight_g
 * @property string|null $final_buyer_total
 * @property string|null $final_seller_gross
 * @property string|null $commission_amount
 * @property string|null $vat_amount
 * @property string|null $spread_amount
 * @property string|null $seller_proceeds
 * @property string|null $balance_amount
 * @property string|null $settlement_txn_id
 * @property string|null $forfeit_txn_id
 * @property string|null $release_txn_id
 * @property CarbonImmutable|null $reach_reminder_sent_at
 * @property CarbonImmutable|null $balance_reminder_sent_at
 */
class Order extends Model
{
    use HasUuids;

    protected $table = 'order';

    protected $primaryKey = 'order_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** Keep microseconds: requests are ordered by the moment they arrived. */
    protected $dateFormat = 'Y-m-d H:i:s.uO';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => OrderState::class,
            'branch_id' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'reach_branch_deadline' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'decision_due_deadline' => 'immutable_datetime',
            'proposed_at' => 'immutable_datetime',
            'balance_due_deadline' => 'immutable_datetime',
            'collect_deadline' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'reach_reminder_sent_at' => 'immutable_datetime',
            'balance_reminder_sent_at' => 'immutable_datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id', 'listing_id');
    }

    public function buyRequest(): BelongsTo
    {
        return $this->belongsTo(BuyRequest::class, 'buy_request_id', 'buy_request_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'branch_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'buyer_id', 'customer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'seller_id', 'customer_id');
    }

    public function stateChanges(): HasMany
    {
        return $this->hasMany(OrderStateChange::class, 'order_id', 'order_id')->orderBy('changed_at')->orderBy('change_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(InspectionResult::class, 'order_id', 'order_id')->orderBy('created_at');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(SettlementDecision::class, 'order_id', 'order_id')->orderBy('decided_at');
    }

    public function branchChanges(): HasMany
    {
        return $this->hasMany(OrderBranchChange::class, 'order_id', 'order_id')->orderBy('changed_at');
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(OrderDeadlineExtension::class, 'order_id', 'order_id')->orderBy('granted_at');
    }

    public function collection(): HasOne
    {
        return $this->hasOne(OrderCollection::class, 'order_id', 'order_id');
    }

    public function sellerReturn(): HasOne
    {
        return $this->hasOne(SellerReturn::class, 'order_id', 'order_id');
    }

    public function sellerCancellation(): HasOne
    {
        return $this->hasOne(SellerCancellation::class, 'order_id', 'order_id');
    }

    /** The result that counts: the newest one no other result supersedes (Part 3 §7.3). */
    public function latestInspection(): ?InspectionResult
    {
        $all = $this->relationLoaded('inspections') ? $this->inspections : $this->inspections()->get();
        $superseded = $all->pluck('supersedes_id')->filter()->all();

        return $all->reject(fn (InspectionResult $r) => in_array($r->inspection_id, $superseded, true))
            ->sortBy(fn (InspectionResult $r) => $r->created_at->format('Y-m-d H:i:s.u'))
            ->last();
    }

    public function isParty(string $customerId): bool
    {
        return $this->seller_id === $customerId || $this->buyer_id === $customerId;
    }
}
