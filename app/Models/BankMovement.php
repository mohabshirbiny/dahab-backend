<?php

namespace App\Models;

use App\Enums\BankMovementKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money that moved in or out of Dahab's bank account outside the app (spec 015
 * FR-010; schema §17). `amount` is signed: + into the bank, − out. Each kind
 * but an own-account transfer has one `external_bank_movement` entry.
 * Append-only.
 *
 * @property string $movement_id
 * @property int $movement_no
 * @property BankMovementKind $kind
 * @property string $amount
 * @property CarbonImmutable $occurred_on
 * @property string $reason
 * @property string|null $proof_ref
 * @property string|null $proof_mime
 * @property string $recorded_by
 * @property string|null $ledger_txn_id
 * @property CarbonImmutable $recorded_at
 */
class BankMovement extends Model
{
    use HasUuids;

    protected $table = 'bank_movement';

    protected $primaryKey = 'movement_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['movement_id', 'movement_no'];

    protected $dateFormat = 'Y-m-d H:i:s.uO';

    protected function casts(): array
    {
        return [
            'kind' => BankMovementKind::class,
            'amount' => 'decimal:4',
            'occurred_on' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function number(): string
    {
        return 'BM-'.$this->movement_no;
    }

    public function direction(): string
    {
        return bccomp((string) $this->amount, '0', 4) > 0 ? 'in' : 'out';
    }

    /** The amount without its sign. */
    public function magnitude(): string
    {
        return ltrim(bcadd((string) $this->amount, '0', 4), '-');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'recorded_by', 'staff_id');
    }
}
