<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Staff turned the first-sale advance on for one seller (spec 023 research R15). Open until revoked or used.
 *
 * @property string $enablement_id
 * @property string $seller_id
 * @property string $enabled_by
 * @property string $reason
 * @property CarbonImmutable $created_at
 * @property string|null $revoked_by
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $used_advance_id
 */
class FirstSaleEnablement extends Model
{
    use HasUuids;

    protected $table = 'first_sale_enablement';

    protected $primaryKey = 'enablement_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
