<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPassword extends Model
{
    protected $table = 'staff_password';

    protected $primaryKey = 'staff_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'staff_id',
        'password_hash',
        'password_changed_at',
        'force_reenroll_mfa_at',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'password_changed_at' => 'datetime',
            'force_reenroll_mfa_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'staff_id');
    }
}
