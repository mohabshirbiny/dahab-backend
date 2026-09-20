<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FounderDeviceApproval extends Model
{
    use HasUuids;

    protected $table = 'founder_device_approval';

    protected $primaryKey = 'approval_id';

    public $timestamps = false;

    protected $fillable = [
        'staff_id',
        'device_fingerprint',
        'requested_at',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['approval_id'];
    }
}
