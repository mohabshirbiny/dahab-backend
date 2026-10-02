<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The buyer's collection of a paid piece (spec 012 FR-015, FR-020; schema
 * §11): a physical handover against a code, no money. The code is stored
 * hashed for the check and encrypted for its owner's view (research R10).
 *
 * @property string $collection_id
 * @property string $order_id
 * @property string $code_hash
 * @property string $code_encrypted
 * @property int $failed_attempts
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable|null $collected_at
 * @property string|null $handover_by
 */
class OrderCollection extends Model
{
    use BelongsToOrder;

    protected $table = 'collection';

    protected $primaryKey = 'collection_id';

    /** @var list<string> */
    protected $hidden = ['code_hash', 'code_encrypted'];

    protected function casts(): array
    {
        return [
            'code_encrypted' => 'encrypted',
            'failed_attempts' => 'integer',
            'locked_until' => 'immutable_datetime',
            'collected_at' => 'immutable_datetime',
            'is_proxy' => 'boolean',
        ];
    }
}
