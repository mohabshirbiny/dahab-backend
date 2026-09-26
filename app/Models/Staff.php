<?php

namespace App\Models;

use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class Staff extends Authenticatable implements HasApiTokensContract
{
    /** @use HasFactory<StaffFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    /**
     * Spatie roles/permissions live on the dashboard ("staff") guard only.
     * Customer deliberately has no HasRoles — see docs/Technical Spec/dahab-dashboard-authorization.md.
     */
    public const GUARD = 'staff';

    protected string $guard_name = self::GUARD;

    protected $table = 'staff';

    protected $primaryKey = 'staff_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    /**
     * `is_founder` and `is_system` are deliberately NOT fillable: founder
     * status is never changeable through the API (spec 002 FR-043) and the
     * system actor is created only by migration (FR-060).
     */
    protected $fillable = [
        'full_name',
        'email',
        'phone',
        'is_active',
        'branch_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_founder' => 'boolean',
            'is_system' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['staff_id'];
    }

    public function password(): HasOne
    {
        return $this->hasOne(StaffPassword::class, 'staff_id', 'staff_id');
    }

    public function mfa(): HasOne
    {
        return $this->hasOne(StaffMfa::class, 'staff_id', 'staff_id');
    }

    public function deviceFingerprints(): HasMany
    {
        return $this->hasMany(StaffDeviceFingerprint::class, 'staff_id', 'staff_id');
    }

    public function activeFreeze(): HasOne
    {
        return $this->hasOne(AccountFreeze::class, 'frozen_staff_id', 'staff_id')
            ->whereNull('unfrozen_at');
    }

    /** Every staff member except the system actor (spec 002 FR-061). */
    public function scopeManageable(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /** Founders always; others when any of their roles requires MFA (spec 002 FR-040). */
    public function requiresMfa(): bool
    {
        return $this->is_founder || $this->roles()->where('requires_mfa', true)->exists();
    }

    /** @return list<string> effective permission codes, sorted (union over roles, FR-022) */
    public function effectivePermissionCodes(): array
    {
        return $this->getAllPermissions()->pluck('name')->sort()->values()->all();
    }
}
