<?php

namespace App\Models;

use App\Enums\DisputeChangeKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a dispute's history (spec 014 R9): opened by a customer, passed
 * on or resolved by staff. Exactly one actor; pass-on notes are staff-only.
 * Append-only; every dispute change has its row in the same transaction.
 *
 * @property int $change_id
 * @property string $dispute_id
 * @property DisputeChangeKind $kind
 * @property string|null $actor_customer_id
 * @property string|null $actor_staff_id
 * @property string|null $assigned_to
 * @property string|null $note
 * @property CarbonImmutable $at
 */
class DisputeChange extends Model
{
    protected $table = 'dispute_change';

    protected $primaryKey = 'change_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uO';

    protected function casts(): array
    {
        return [
            'kind' => DisputeChangeKind::class,
            'at' => 'immutable_datetime',
        ];
    }

    public function actorStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'actor_staff_id', 'staff_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_to', 'staff_id');
    }
}
