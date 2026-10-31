<?php

namespace App\Models;

use Database\Factories\LabFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An inspection lab (docs/features/labs-and-certificate-costs.md): what its certificates and its
 * gold inspection cost the seller, and its branches. Never deleted, only disabled, because records
 * keep referencing it.
 *
 * @property int $lab_id
 * @property string $name_en
 * @property string $name_ar
 * @property string $certificate_own_cost_egp
 * @property string $certificate_other_cost_egp
 * @property string $certificate_none_cost_egp
 * @property string $gold_inspection_cost_egp
 * @property bool $is_enabled
 */
class Lab extends Model
{
    /** @use HasFactory<LabFactory> */
    use HasFactory;

    protected $table = 'lab';

    protected $primaryKey = 'lab_id';

    public $timestamps = false;

    public const EDITABLE = ['name_en', 'name_ar', 'certificate_own_cost_egp', 'certificate_other_cost_egp', 'certificate_none_cost_egp', 'gold_inspection_cost_egp', 'is_enabled'];

    protected $fillable = self::EDITABLE;

    private ?bool $open = null;

    protected function casts(): array
    {
        return [
            'lab_id' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    /** The main lab: IGI, the first one, created by the migration. */
    public static function main(): self
    {
        return self::query()->orderBy('lab_id')->firstOrFail();
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'lab_id', 'lab_id');
    }

    /** Enabled, with an enabled branch to bring a piece to. Asked once per loaded row. */
    public function isOpen(): bool
    {
        return $this->open ??= $this->is_enabled && $this->branches()->where('is_enabled', true)->exists();
    }

    /** @return array{id: int, name_en: string, name_ar: string} */
    public function summary(): array
    {
        return ['id' => $this->lab_id, 'name_en' => $this->name_en, 'name_ar' => $this->name_ar];
    }
}
