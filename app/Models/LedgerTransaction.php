<?php

namespace App\Models;

use App\Enums\LedgerEventKind;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One ledger entry: one business event whose lines sum to zero (spec 008).
 * Written only by App\Actions\Ledger\PostLedgerEntryAction; append-only.
 *
 * @property string $ledger_txn_id
 * @property LedgerEventKind $event_kind
 * @property string|null $customer_id
 * @property string|null $staff_id
 * @property string|null $memo
 * @property string|null $reverses_txn_id
 */
class LedgerTransaction extends Model
{
    use AppendOnly;

    protected $table = 'ledger_transaction';

    protected $primaryKey = 'ledger_txn_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_kind' => LedgerEventKind::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function postings(): HasMany
    {
        return $this->hasMany(LedgerPosting::class, 'ledger_txn_id', 'ledger_txn_id')->orderBy('posting_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_txn_id', 'ledger_txn_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_txn_id', 'ledger_txn_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'staff_id');
    }
}
