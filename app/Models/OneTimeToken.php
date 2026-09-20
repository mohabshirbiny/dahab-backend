<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OneTimeToken extends Model
{
    use HasUuids;

    protected $table = 'one_time_token';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $fillable = [
        'token_hash',
        'purpose',
        'actor_customer_id',
        'actor_staff_id',
        'payload',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('consumed_at')->where('expires_at', '>', now());
    }
}
