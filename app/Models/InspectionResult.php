<?php

namespace App\Models;

use App\Enums\InspectionOutcome;
use App\Models\Concerns\BelongsToOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An inspection result (spec 012 FR-011; schema §10). Immutable: a correction
 * is a new row naming the one it supersedes (`inspection_no_update`). The
 * outcome is derived by the server, never sent by the client (research R9).
 *
 * @property string $inspection_id
 * @property string $order_id
 * @property int $branch_id
 * @property string $inspected_by
 * @property int|null $stated_karat
 * @property string|null $stated_weight_g
 * @property int|null $measured_karat
 * @property string|null $measured_weight_g
 * @property string|null $measured_stone_grade
 * @property string|null $certificate_number
 * @property string|null $inspector_note
 * @property bool $is_counterfeit
 * @property bool $stone_below_claim
 * @property bool $karat_mismatch
 * @property string|null $weight_diff_pct
 * @property InspectionOutcome $outcome
 * @property string|null $supersedes_id
 * @property CarbonImmutable $created_at
 */
class InspectionResult extends Model
{
    use BelongsToOrder;

    protected $table = 'inspection_result';

    protected $primaryKey = 'inspection_id';

    /** Keep microseconds: the latest result is the one that counts. */
    protected $dateFormat = 'Y-m-d H:i:s.uO';

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'stated_karat' => 'integer',
            'measured_karat' => 'integer',
            'stated_weight_g' => 'decimal:3',
            'measured_weight_g' => 'decimal:3',
            'weight_diff_pct' => 'decimal:4',
            'is_counterfeit' => 'boolean',
            'stone_below_claim' => 'boolean',
            'karat_mismatch' => 'boolean',
            'outcome' => InspectionOutcome::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'inspected_by', 'staff_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'branch_id');
    }
}
