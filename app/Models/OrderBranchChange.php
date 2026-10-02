<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A staff change of an open order's branch (spec 012 FR-008; schema §9). The
 * reach-branch clock keeps running unless staff extend it (`extended_to`).
 *
 * @property string $change_id
 * @property string $order_id
 * @property int $from_branch
 * @property int $to_branch
 * @property string $changed_by
 * @property CarbonImmutable|null $extended_to
 * @property string $reason
 * @property CarbonImmutable $changed_at
 */
class OrderBranchChange extends Model
{
    use BelongsToOrder;

    protected $table = 'order_branch_change';

    protected $primaryKey = 'change_id';

    protected function casts(): array
    {
        return [
            'from_branch' => 'integer',
            'to_branch' => 'integer',
            'extended_to' => 'immutable_datetime',
            'changed_at' => 'immutable_datetime',
        ];
    }
}
