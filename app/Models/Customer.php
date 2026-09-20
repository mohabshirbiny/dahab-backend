<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\Governorate;
use App\Enums\SuspendedReason;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;

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

    public function identityDocuments(): HasMany
    {
        return $this->hasMany(IdentityDocument::class, 'customer_id', 'customer_id');
    }

    public function trustedDevices(): HasMany
    {
        return $this->hasMany(CustomerTrustedDevice::class, 'customer_id', 'customer_id');
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
     */
    public function transitionTo(CustomerStatus $next): void
    {
        $flags = $next->legacyFlags();

        $this->fill([
            'status' => $next,
            'is_verified' => $flags['is_verified'],
            'is_suspended' => $flags['is_suspended'],
        ]);
    }
}
