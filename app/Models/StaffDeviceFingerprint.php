<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StaffDeviceFingerprint extends Model
{
    use HasUuids;

    protected $table = 'staff_device_fingerprint';

    public $timestamps = false;

    protected $fillable = [
        'staff_id',
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
