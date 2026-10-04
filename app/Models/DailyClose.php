<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Cairo day compared against the bank statement (spec 015 FR-014; schema
 * §17). Saved unlocked until it is locked; a locked row never changes
 * (`daily_close_no_reopen`).
 *
 * @property CarbonImmutable $close_date
 * @property string $bank_balance
 * @property string $books_bank
 * @property string $customer_available
 * @property string $customer_held
 * @property string $customer_liability
 * @property string $dahab_wallet
 * @property string $escrow
 * @property string $vat_payable
 * @property string $movements_in
 * @property string $movements_out
 * @property string $difference
 * @property string|null $explanation
 * @property bool $is_locked
 * @property string $saved_by
 * @property CarbonImmutable $saved_at
 * @property string|null $closed_by
 * @property CarbonImmutable|null $closed_at
 */
class DailyClose extends Model
{
    protected $table = 'daily_close';

    protected $primaryKey = 'close_date';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uO';

    protected function casts(): array
    {
        $money = 'decimal:4';

        return [
            'close_date' => 'immutable_date',
            'bank_balance' => $money, 'books_bank' => $money, 'customer_available' => $money, 'customer_held' => $money,
            'customer_liability' => $money, 'dahab_wallet' => $money, 'escrow' => $money, 'vat_payable' => $money,
            'movements_in' => $money, 'movements_out' => $money, 'difference' => $money,
            'is_locked' => 'boolean',
            'saved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function saver(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'saved_by', 'staff_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'closed_by', 'staff_id');
    }
}
