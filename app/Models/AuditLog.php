<?php

namespace App\Models;

use App\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_log';

    protected $primaryKey = 'audit_id';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_staff_id',
        'actor_customer_id',
        'action',
        'entity_type',
        'entity_id',
        'before_json',
        'after_json',
        'reason',
        'ip_address',
        'device_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function forEvent(AuditEvent $event): self
    {
        return static::query()->where('action', $event->value)->firstOrFail();
    }
}
