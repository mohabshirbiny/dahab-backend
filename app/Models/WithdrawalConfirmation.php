<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The email second-check on a withdrawal (Part 1 §2.4; spec 013 research R5):
 * single use, tied to one customer, amount and account, valid for a short
 * time. Only the HMAC of the emailed token is stored.
 *
 * @property string $confirmation_id
 * @property string $customer_id
 * @property string $payout_account_id
 * @property string $amount
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable|null $replaced_at
 * @property string|null $withdrawal_id
 */
class WithdrawalConfirmation extends Model
{
    use HasUuids;

    public const SENT = 'sent';

    public const CONFIRMED = 'confirmed';

    public const USED = 'used';

    public const EXPIRED = 'expired';

    public const REPLACED = 'replaced';

    protected $table = 'withdrawal_confirmation';

    protected $primaryKey = 'confirmation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['confirmation_id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'replaced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** Derived: used and replaced win over expiry; an unexpired one is sent or confirmed. */
    public function state(): string
    {
        return match (true) {
            $this->used_at !== null => self::USED,
            $this->replaced_at !== null => self::REPLACED,
            $this->expires_at->isPast() => self::EXPIRED,
            $this->confirmed_at !== null => self::CONFIRMED,
            default => self::SENT,
        };
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class, 'payout_account_id', 'payout_account_id');
    }
}
