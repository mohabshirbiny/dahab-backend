<?php

namespace App\Models;

use App\Enums\BuyRequestState;
use App\Enums\ListingMediaKind;
use App\Enums\ListingState;
use App\Enums\PieceCategory;
use Carbon\CarbonImmutable;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A piece put up for sale (spec 010, table `listing`). Weight is NUMERIC(10,3)
 * and money NUMERIC(18,4), both strings; never floats. The state changes only
 * through App\Actions\Listings\Concerns\MovesListing: the database refuses a
 * move that is not in `listing_transition` or that leaves no history row.
 *
 * @property string $listing_id
 * @property string $seller_id
 * @property PieceCategory $category
 * @property int $piece_type_id
 * @property int|null $karat_code
 * @property string|null $stated_weight_g
 * @property string|null $making_charge_per_g
 * @property string|null $asking_price
 * @property string|null $description
 * @property string|null $relisted_from_order_id spec 018: set when this listing is a free relist (the 0% commission waiver)
 * @property ListingState $state
 * @property int $active_queue_count
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $listed_at
 * @property CarbonImmutable $state_changed_at
 */
class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory, HasUuids;

    protected $table = 'listing';

    protected $primaryKey = 'listing_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['listing_id', 'listed_at', 'state_changed_at'];

    protected function casts(): array
    {
        return [
            'category' => PieceCategory::class,
            'state' => ListingState::class,
            'piece_type_id' => 'integer',
            'karat_code' => 'integer',
            'active_queue_count' => 'integer',
            'created_at' => 'immutable_datetime',
            'listed_at' => 'immutable_datetime',
            'state_changed_at' => 'immutable_datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'seller_id', 'customer_id');
    }

    /** Spec 018: a free relist is linked to the order it was relisted from; the link is the waiver. */
    public function isFreeRelist(): bool
    {
        return $this->relisted_from_order_id !== null;
    }

    public function relistedFrom(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'relisted_from_order_id', 'order_id');
    }

    public function pieceType(): BelongsTo
    {
        return $this->belongsTo(PieceType::class, 'piece_type_id', 'piece_type_id');
    }

    public function karat(): BelongsTo
    {
        return $this->belongsTo(Karat::class, 'karat_code', 'karat_code');
    }

    /** Every media item: photos in their order, then the video, invoice and certificate. */
    public function media(): HasMany
    {
        return $this->hasMany(ListingMedia::class, 'listing_id', 'listing_id')
            ->orderByRaw("CASE kind WHEN 'photo' THEN 0 WHEN 'video' THEN 1 WHEN 'stone_certificate' THEN 2 ELSE 3 END")
            ->orderBy('position')->orderBy('created_at')->orderBy('media_id');
    }

    public function photos(): HasMany
    {
        return $this->media()->where('kind', ListingMediaKind::PHOTO->value);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'listing_branch_option', 'listing_id', 'branch_id', 'listing_id', 'branch_id')
            ->orderBy('branch.branch_id');
    }

    public function declaration(): HasOne
    {
        return $this->hasOne(ListingOwnershipDeclaration::class, 'listing_id', 'listing_id');
    }

    /** The history, oldest first. */
    public function changes(): HasMany
    {
        return $this->hasMany(ListingStateChange::class, 'listing_id', 'listing_id')->orderBy('change_id');
    }

    /** Every buy request on this piece (spec 011). */
    public function buyRequests(): HasMany
    {
        return $this->hasMany(BuyRequest::class, 'listing_id', 'listing_id');
    }

    /** The line: queued requests in arrival order (spec 011 FR-013). */
    public function queuedRequests(): HasMany
    {
        return $this->buyRequests()->where('state', BuyRequestState::QUEUED->value)->orderBy('queue_position');
    }

    /** The latest order on this piece (spec 011: created at acceptance). */
    public function order(): HasOne
    {
        // Not latestOfMany(): it aggregates the uuid key with max(), which PostgreSQL lacks.
        // Eager loading keeps the first row per listing in this order.
        return $this->hasOne(Order::class, 'listing_id', 'listing_id')->orderByDesc('accepted_at')->orderByDesc('order_id');
    }

    /** What the public market shows (spec 010 FR-020). */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('listing.state', array_map(fn (ListingState $s) => $s->value, ListingState::PUBLIC));
    }

    /** "Gold ring, 21K" — for messages to the seller and the staff queue. */
    public function title(bool $arabic = false): string
    {
        $type = $arabic ? $this->pieceType?->name_ar : $this->pieceType?->name_en;

        $lead = match ($this->category) {
            PieceCategory::GOLD => $arabic ? 'دهب' : 'Gold',
            PieceCategory::DIAMOND => $arabic ? 'ألماظ' : 'Diamond',
            PieceCategory::GOLD_WITH_DIAMOND => $arabic ? 'دهب وألماظ' : 'Gold and diamond',
        };

        $title = $arabic ? trim(($type ?? '').' '.$lead) : trim($lead.' '.mb_strtolower($type ?? 'piece'));

        return $this->karat_code === null ? $title : $title.', '.$this->karat_code.'K';
    }
}
