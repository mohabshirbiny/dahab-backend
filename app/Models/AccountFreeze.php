<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AccountFreeze extends Model
{
    use HasUuids;

    protected $table = 'account_freeze';

    protected $primaryKey = 'freeze_id';

    public $timestamps = false;

    protected $fillable = [
        'frozen_staff_id',
        'frozen_by',
        'frozen_at',
        'unfreeze_confirm_1',
        'unfreeze_confirm_2',
        'unfrozen_at',
    ];

    protected function casts(): array
    {
        return [
            'frozen_at' => 'datetime',
            'unfrozen_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['freeze_id'];
    }
}
