<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One open interval of a branch's weekly template (dow 0 = Sunday … 6 =
 * Saturday). Read through Branch::hours(); a branch's week is always
 * replaced as a whole by UpdateBranchAction (composite key, so there are no
 * single-row updates).
 */
class BranchHour extends Model
{
    protected $table = 'branch_hours';

    public $incrementing = false;

    protected $primaryKey = null;

    public $timestamps = false;

    protected $fillable = ['branch_id', 'dow', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return ['dow' => 'integer'];
    }
}
