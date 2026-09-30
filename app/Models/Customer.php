<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\Governorate;
use App\Enums\SuspendedReason;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;
use LogicException;

class Customer extends Authenticatable implements HasApiTokensContract
{
    /** @use HasFactory<CustomerFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $table = 'customer';

    protected $primaryKey = 'customer_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $fillable = [
        'display_ref',
        'phone',
        'email',
        'email_verified_at',
        'full_name',
        'preferred_lang',
        'status',
        'governorate',
        'customer_type',
        'is_verified',
        'is_suspended',
        'suspended_reason',
        'suspended_by',
        'suspended_at',
        'suspended_note',
        'status_before_suspension',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'is_suspended' => 'boolean',
            'email_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'suspended_reason' => SuspendedReason::class,
            'status' => CustomerStatus::class,
            'status_before_suspension' => CustomerStatus::class,
            'governorate' => Governorate::class,
            'customer_type' => CustomerType::class,
            'created_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['customer_id'];
    }

    /** Recipient for the `sms` notification channel. */
    public function routeNotificationForSms(): ?string
    {
        return $this->phone;
    }

    public function password(): HasOne
    {
        return $this->hasOne(CustomerPassword::class, 'customer_id', 'customer_id');
    }

    /** The customer's two wallet accounts, available and held (spec 008). */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'customer_id', 'customer_id');
    }

    /** Transfer notices and hand credits (spec 009). */
    public function topUps(): HasMany
    {
        return $this->hasMany(TopUp::class, 'customer_id', 'customer_id');
    }

    /** The pieces this customer has put up for sale (spec 010). */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'seller_id', 'customer_id');
    }

    public function identityDocuments(): HasMany
    {
        return $this->hasMany(IdentityDocument::class, 'customer_id', 'customer_id');
    }

    public function trustedDevices(): HasMany
    {
        return $this->hasMany(CustomerTrustedDevice::class, 'customer_id', 'customer_id');
    }

    /** The staff member who suspended the customer (while suspended). */
    public function suspender(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'suspended_by', 'staff_id');
    }

    public function tradeAllowed(): bool
    {
        return $this->status === CustomerStatus::ACTIVE;
    }

    /**
     * Set the lifecycle state and keep the legacy `is_verified` / `is_suspended`
     * flags in lock-step with it. Callers should NEVER mutate the flags
     * directly — a bare `update(['is_verified' => true])` bypasses the
     * invariant. Use this method (or persist status via the identity Actions).
     *
     * Suspension is entered and left only through suspend() / reinstate(),
     * which remember the interrupted state (spec 007 research R3).
     */
    public function transitionTo(CustomerStatus $next): void
    {
        if ($next === CustomerStatus::SUSPENDED || $this->status === CustomerStatus::SUSPENDED) {
            throw new LogicException('Use suspend() / reinstate() to enter or leave suspension.');
        }

        $flags = $next->legacyFlags();

        $this->fill([
            'status' => $next,
            'is_verified' => $flags['is_verified'],
            'is_suspended' => $flags['is_suspended'],
        ]);
    }

    /**
     * Suspend from any other state, remembering it. `is_verified` keeps
     * describing the interrupted state (true only when it was `active`).
     * The caller locks the row, checks for conflicts, saves and audits.
     */
    public function suspend(SuspendedReason $reason, string $note, Staff $by): void
    {
        if ($this->status === CustomerStatus::SUSPENDED) {
            throw new LogicException('The customer is already suspended.');
        }

        $this->fill([
            'status_before_suspension' => $this->status,
            'status' => CustomerStatus::SUSPENDED,
            'is_verified' => $this->status === CustomerStatus::ACTIVE,
            'is_suspended' => true,
            'suspended_reason' => $reason,
            'suspended_note' => $note,
            'suspended_by' => $by->staff_id,
            'suspended_at' => now(),
        ]);
    }

    /** Return to the state the suspension interrupted and clear its details. */
    public function reinstate(): void
    {
        $previous = $this->status_before_suspension;

        if ($this->status !== CustomerStatus::SUSPENDED || ! $previous instanceof CustomerStatus) {
            throw new LogicException('The customer is not suspended.');
        }

        $flags = $previous->legacyFlags();

        $this->fill([
            'status' => $previous,
            'is_verified' => $flags['is_verified'],
            'is_suspended' => false,
            'status_before_suspension' => null,
            'suspended_reason' => null,
            'suspended_note' => null,
            'suspended_by' => null,
            'suspended_at' => null,
        ]);
    }

    /**
     * While suspended, an identity review changes the state a reinstatement
     * returns to, never the suspension itself (spec 007 research R6).
     */
    public function setStateBehindSuspension(CustomerStatus $state): void
    {
        if ($this->status !== CustomerStatus::SUSPENDED || $state === CustomerStatus::SUSPENDED) {
            throw new LogicException('Only a suspended customer has a state behind the suspension.');
        }

        $this->fill([
            'status_before_suspension' => $state,
            'is_verified' => $state === CustomerStatus::ACTIVE,
        ]);
    }
}
