<?php

namespace App\Models;

use Database\Factories\AccountFreezeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A freeze of a staff account (spec 021 Story 2). Unfreezing takes two confirmations in order:
 * slot 1 from the frozen founder or a holder of `founder.unfreeze_second`, slot 2 (always last)
 * from a founder who is not frozen. CHECKs and guard DH022 hold the rules in the database.
 */
class AccountFreeze extends Model
{
    /** @use HasFactory<AccountFreezeFactory> */
    use HasFactory, HasUuids;

    public const STAGE_AWAITING_FIRST = 'awaiting_first';

    public const STAGE_AWAITING_FOUNDER = 'awaiting_founder';

    public const STAGE_UNFROZEN = 'unfrozen';

    public const KIND_FROZEN_FOUNDER = 'frozen_founder';

    public const KIND_SECOND_APPROVER = 'second_approver';

    protected $table = 'account_freeze';

    protected $primaryKey = 'freeze_id';

    public $timestamps = false;

    protected $fillable = [
        'frozen_staff_id',
        'frozen_by',
        'frozen_at',
        'reason',
        'unfreeze_confirm_1',
        'unfreeze_confirm_1_at',
        'unfreeze_confirm_1_kind',
        'unfreeze_confirm_2',
        'unfreeze_confirm_2_at',
        'unfrozen_at',
    ];

    protected function casts(): array
    {
        return [
            'frozen_at' => 'datetime',
            'unfreeze_confirm_1_at' => 'datetime',
            'unfreeze_confirm_2_at' => 'datetime',
            'unfrozen_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['freeze_id'];
    }

    public function frozenStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'frozen_staff_id', 'staff_id');
    }

    public function frozenBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'frozen_by', 'staff_id');
    }

    public function confirmer1(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'unfreeze_confirm_1', 'staff_id');
    }

    public function confirmer2(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'unfreeze_confirm_2', 'staff_id');
    }

    public function stage(): string
    {
        return match (true) {
            $this->unfrozen_at !== null => self::STAGE_UNFROZEN,
            $this->unfreeze_confirm_1 !== null => self::STAGE_AWAITING_FOUNDER,
            default => self::STAGE_AWAITING_FIRST,
        };
    }

    public function isOpen(): bool
    {
        return $this->unfrozen_at === null;
    }
}
