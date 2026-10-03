<?php

namespace App\Models;

use App\Enums\PayoutAccountChangeKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a customer's payout-account history (spec 013, table
 * `payout_account_change`): what happened, to which account, by whom.
 * Append-only.
 *
 * @property int $change_id
 * @property string $customer_id
 * @property string $payout_account_id
 * @property PayoutAccountChangeKind $kind
 * @property string|null $actor_customer_id
 * @property string|null $actor_staff_id
 * @property string|null $pause_id
 */
class PayoutAccountChange extends Model
{
    protected $table = 'payout_account_change';

    protected $primaryKey = 'change_id';

    public $timestamps = false;

    protected $guarded = ['change_id'];

    protected function casts(): array
    {
        return [
            'kind' => PayoutAccountChangeKind::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class, 'payout_account_id', 'payout_account_id');
    }
}
