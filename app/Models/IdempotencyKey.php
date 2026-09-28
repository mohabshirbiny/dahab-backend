<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Idempotency-Key use (spec 007 research R2). Written only by
 * App\Http\Middleware\EnforceIdempotency and pruned by idempotency:prune.
 */
class IdempotencyKey extends Model
{
    public const STATE_IN_FLIGHT = 'in_flight';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    protected $table = 'idempotency_key';

    public $timestamps = false;

    protected $fillable = [
        'idem_key',
        'actor_kind',
        'actor_customer_id',
        'actor_staff_id',
        'endpoint',
        'request_hash',
        'state',
        'response_status',
        'response_body',
        'created_at',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
