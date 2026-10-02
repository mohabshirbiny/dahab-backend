<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A piece going back to its seller (spec 012 FR-018, FR-019; schema §11):
 * after a no-pay (with the forfeit compensation) or an inspection cancel /
 * declined adjustment (none). The seller collects it with a code or relists
 * it; `return_deadline` is calendar time (Part 3 §1.3).
 *
 * @property string $seller_return_id
 * @property string $order_id
 * @property string $listing_id
 * @property string $seller_id
 * @property int $branch_id
 * @property string $code_hash
 * @property string $code_encrypted
 * @property int $failed_attempts
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable $return_deadline
 * @property CarbonImmutable|null $collected_at
 * @property string|null $handover_by
 * @property CarbonImmutable|null $relisted_at
 * @property string|null $compensation_txn_id
 * @property CarbonImmutable $created_at
 */
class SellerReturn extends Model
{
    use BelongsToOrder;

    protected $table = 'seller_return';

    protected $primaryKey = 'seller_return_id';

    /** @var list<string> */
    protected $hidden = ['code_hash', 'code_encrypted'];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'code_encrypted' => 'encrypted',
            'failed_attempts' => 'integer',
            'locked_until' => 'immutable_datetime',
            'return_deadline' => 'immutable_datetime',
            'collected_at' => 'immutable_datetime',
            'relisted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->collected_at === null && $this->relisted_at === null;
    }
}
