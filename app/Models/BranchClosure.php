<?php

namespace App\Models;

use Database\Factories\BranchClosureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A full day a branch is shut; `branch_id` NULL = every branch (a national
 * holiday). Contributes zero working time (Part 3 §1.2).
 */
class BranchClosure extends Model
{
    /** @use HasFactory<BranchClosureFactory> */
    use HasFactory;

    protected $table = 'branch_closure';

    protected $primaryKey = 'closure_id';

    public $timestamps = false;

    protected $fillable = ['branch_id', 'closure_date', 'reason_en', 'reason_ar'];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'closure_date' => 'date:Y-m-d',
        ];
    }
}
