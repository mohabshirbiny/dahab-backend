<?php

namespace App\Models;

use App\Enums\StaffRole;
use Database\Factories\StaffFactory;
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

    protected $fillable = [
        'role',
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
            'role' => StaffRole::class,
            'is_active' => 'boolean',
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
}
