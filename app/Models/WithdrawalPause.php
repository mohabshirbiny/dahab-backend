<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A window in which a customer may not ask to withdraw, opened when the
 * account in use changes (spec 013, table `withdrawal_pause`).
 *
 * @property string $pause_id
 * @property string $customer_id
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable $pause_until
 * @property string|null $triggered_by_account
 * @property CarbonImmutable|null $ended_notified_at
 */
class WithdrawalPause extends Model
{
    use HasUuids;

    protected $table = 'withdrawal_pause';

    protected $primaryKey = 'pause_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['pause_id'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime',
            'pause_until' => 'immutable_datetime',
            'ended_notified_at' => 'immutable_datetime',
        ];
    }

    /** The latest pause still covering now, if any. */
    public static function openFor(string $customerId): ?self
    {
        return self::query()->where('customer_id', $customerId)
            ->where('pause_until', '>', now())
            ->orderByDesc('pause_until')->first();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('pause_until', '>', now());
    }
}
