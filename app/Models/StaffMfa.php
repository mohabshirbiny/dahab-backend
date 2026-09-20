<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffMfa extends Model
{
    protected $table = 'staff_mfa';

    protected $primaryKey = 'staff_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'staff_id',
        'mfa_secret_encrypted',
        'enrolled_at',
        'recovery_codes_hash',
    ];

    protected $hidden = ['mfa_secret_encrypted', 'recovery_codes_hash'];

    protected function casts(): array
    {
        return [
            'mfa_secret_encrypted' => 'encrypted',
            'recovery_codes_hash' => 'array',
            'enrolled_at' => 'datetime',
        ];
    }
}
