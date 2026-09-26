<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Role;

/**
 * A Dashboard-managed staff role (spec 002, data-model §1). Extends Spatie's
 * Role with a display name, a description and the "requires MFA" flag.
 * `name` is the machine name: set once at creation, never changed.
 *
 * Registered as `permission.models.role`, so every Spatie call
 * (`assignRole`, `Role::findOrCreate`, …) returns this class.
 *
 * @property int $id
 * @property string $name
 * @property string $display_name
 * @property string|null $description
 * @property bool $requires_mfa
 */
class StaffRoleModel extends Role
{
    protected $fillable = [
        'name',
        'guard_name',
        'display_name',
        'description',
        'requires_mfa',
    ];

    protected function casts(): array
    {
        return [
            'requires_mfa' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $role) {
            $role->guard_name ??= Staff::GUARD;
            $role->display_name ??= ucwords(str_replace('_', ' ', $role->name));
        });

        static::updating(function (self $role) {
            if ($role->isDirty('name')) {
                throw new LogicException('A role machine name is immutable.');
            }
        });
    }

    /** Adds `staff_count`: holders excluding the system actor. */
    public function scopeWithHolderCount(Builder $query): Builder
    {
        return $query->addSelect([
            'staff_count' => DB::table('model_has_roles')
                ->join('staff', 'staff.staff_id', '=', 'model_has_roles.model_id')
                ->whereColumn('model_has_roles.role_id', 'roles.id')
                ->where('model_has_roles.model_type', Staff::class)
                ->where('staff.is_system', false)
                ->selectRaw('count(*)'),
        ]);
    }

    public function holderCount(): int
    {
        return DB::table('model_has_roles')
            ->join('staff', 'staff.staff_id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.role_id', $this->id)
            ->where('model_has_roles.model_type', Staff::class)
            ->where('staff.is_system', false)
            ->count();
    }
}
