<?php

namespace App\Models;

use App\Enums\DeadlineKind;
use App\Models\Concerns\BelongsToOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A staff extension of an order deadline (spec 012 FR-009; schema §9): the
 * old and new instants, always forward (`extension_moves_forward`).
 *
 * @property string $extension_id
 * @property string $order_id
 * @property DeadlineKind $which
 * @property CarbonImmutable $old_deadline
 * @property CarbonImmutable $new_deadline
 * @property string $granted_by
 * @property string $reason
 * @property CarbonImmutable $granted_at
 */
class OrderDeadlineExtension extends Model
{
    use BelongsToOrder;

    protected $table = 'order_deadline_extension';

    protected $primaryKey = 'extension_id';

    protected function casts(): array
    {
        return [
            'which' => DeadlineKind::class,
            'old_deadline' => 'immutable_datetime',
            'new_deadline' => 'immutable_datetime',
            'granted_at' => 'immutable_datetime',
        ];
    }
}
