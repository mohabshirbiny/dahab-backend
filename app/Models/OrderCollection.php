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
 * @property CarbonImmutable|null $free_relist_until spec 018: end of the buyer's free-relist window, set once at handover
 * @property string|null $handover_by
 * @property bool $is_proxy
 * @property string|null $proxy_name
 * @property string|null $proxy_phone
 * @property string|null $proxy_id_storage_ref
 * @property string|null $proxy_acceptance_id
 * @property CarbonImmutable|null $proxy_named_at
 * @property bool $collected_by_proxy
 * @property string|null $proxy_id_checked_by
 */
class OrderCollection extends Model
{
    use BelongsToOrder;

    protected $table = 'collection';

    protected $primaryKey = 'collection_id';

    /** @var list<string> */
    protected $hidden = ['code_hash', 'code_encrypted', 'proxy_id_storage_ref'];

    protected function casts(): array
    {
        return [
            'code_encrypted' => 'encrypted',
            'failed_attempts' => 'integer',
            'locked_until' => 'immutable_datetime',
            'collected_at' => 'immutable_datetime',
            'free_relist_until' => 'immutable_datetime',
            'is_proxy' => 'boolean',
            'proxy_named_at' => 'immutable_datetime',
            'collected_by_proxy' => 'boolean',
        ];
    }

    /** Spec 014: a proxy is named now and the piece is not yet collected. */
    public function hasProxy(): bool
    {
        return $this->is_proxy && $this->proxy_name !== null;
    }
}
