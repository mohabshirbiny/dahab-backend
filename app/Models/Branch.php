<?php

namespace App\Models;

use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An inspection branch (schema §2, spec 004). Its weekly hours and closures
 * drive every working-hours deadline, in its own timezone. Branches are
 * never deleted, only disabled, because records keep referencing them.
 *
 * @property int $branch_id
 * @property string $timezone
 * @property bool $is_enabled
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    protected $table = 'branch';

    protected $primaryKey = 'branch_id';

    public $timestamps = false;

    /** The fields the Dashboard edits (the week is replaced separately). */
    public const EDITABLE = ['name_en', 'name_ar', 'address_en', 'address_ar', 'timezone', 'is_enabled'];

    protected $fillable = self::EDITABLE;

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    public function hours(): HasMany
    {
        return $this->hasMany(BranchHour::class, 'branch_id', 'branch_id')->orderBy('dow')->orderBy('opens_at');
    }
}
