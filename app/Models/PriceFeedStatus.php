<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The price feed's health: the last good and the last failed reading (spec 005). */
class PriceFeedStatus extends Model
{
    public const PROVIDER = 'default';

    protected $table = 'price_feed_status';

    protected $primaryKey = 'provider';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
        ];
    }

    public static function row(): self
    {
        return self::query()->firstOrNew(['provider' => self::PROVIDER]);
    }
}
