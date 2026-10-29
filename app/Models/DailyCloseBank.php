<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's line of a daily close (owner notes of 2026-10-29,
 * `docs/features/bank-per-account.md`): the statement balance typed for the
 * account, its books at the cut-off and the difference. The line with no
 * account is the books not assigned to any account (nothing typed). Written by
 * CloseDayAction only; the lines of a locked day never change.
 *
 * @property string $close_date
 * @property int|null $receiving_account_id
 * @property string|null $statement_balance
 * @property string $books_balance
 * @property string $difference
 */
class DailyCloseBank extends Model
{
    protected $table = 'daily_close_bank';

    protected $primaryKey = 'close_date';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'statement_balance' => 'decimal:4',
            'books_balance' => 'decimal:4',
            'difference' => 'decimal:4',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ReceivingAccount::class, 'receiving_account_id', 'receiving_account_id');
    }
}
