<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed line of a ledger entry: + increases the account's balance,
 * − decreases it (spec 008). Append-only.
 *
 * @property int $posting_id
 * @property string $ledger_txn_id
 * @property string $account_id
 * @property string $amount decimal string, 4 places — never a float
 */
class LedgerPosting extends Model
{
    use AppendOnly;

    protected $table = 'ledger_posting';

    protected $primaryKey = 'posting_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_txn_id', 'ledger_txn_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'account_id');
    }
}
