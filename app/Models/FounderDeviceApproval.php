<?php

namespace App\Models;

use Database\Factories\FounderDeviceApprovalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A founder's sign-in from an unknown device, held until the other founder decides (spec 021
 * Story 1). The status is derived, never stored (data-model §1.1); the row is guarded by DH021.
 * `ref_hash` is the HMAC of the poll ref the held device keeps; the ref itself is never stored.
 */
class FounderDeviceApproval extends Model
{
    /** @use HasFactory<FounderDeviceApprovalFactory> */
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REFUSED, self::STATUS_EXPIRED];

    protected $table = 'founder_device_approval';

    protected $primaryKey = 'approval_id';

    public $timestamps = false;

    protected $fillable = [
        'staff_id',
        'device_fingerprint',
        'requested_at',
        'expires_at',
        'ref_hash',
        'platform',
        'user_agent',
        'ip_address',
        'approved_by',
        'approved_at',
        'refused_by',
        'refused_at',
        'decision_note',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'refused_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['approval_id'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'staff_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approved_by', 'staff_id');
    }

    public function refuser(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'refused_by', 'staff_id');
    }

    public function status(): string
    {
        return match (true) {
            $this->approved_at !== null => self::STATUS_APPROVED,
            $this->refused_at !== null => self::STATUS_REFUSED,
            now()->gte($this->expires_at) => self::STATUS_EXPIRED,
            default => self::STATUS_PENDING,
        };
    }

    public function isPending(): bool
    {
        return $this->status() === self::STATUS_PENDING;
    }

    /** The first 8 hex characters of the fingerprint hash: enough to tell devices apart, never the id. */
    public function deviceRef(): string
    {
        return substr($this->device_fingerprint, 0, 8);
    }

    /** Undecided and not expired. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('approved_at')->whereNull('refused_at')->where('expires_at', '>', now());
    }

    /** One status of the derived four. */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            self::STATUS_PENDING => $query->pending(),
            self::STATUS_APPROVED => $query->whereNotNull('approved_at'),
            self::STATUS_REFUSED => $query->whereNotNull('refused_at'),
            self::STATUS_EXPIRED => $query->whereNull('approved_at')->whereNull('refused_at')->where('expires_at', '<=', now()),
            default => $query,
        };
    }

    /** The requests another founder may decide: everyone's but the approver's own. */
    public function scopeForApprover(Builder $query, Staff $approver): Builder
    {
        return $query->where('staff_id', '<>', $approver->staff_id);
    }
}
