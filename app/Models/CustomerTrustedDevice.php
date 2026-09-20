<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomerTrustedDevice extends Model
{
    use HasUuids;

    protected $table = 'customer_trusted_device';

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'fingerprint_hash',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
