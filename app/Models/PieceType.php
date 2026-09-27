<?php

namespace App\Models;

use App\Enums\PieceCategory;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of piece a seller can list, per category (schema §2, spec 004).
 * Seeded; management from the Dashboard is deferred (spec 004 Q2).
 */
class PieceType extends Model
{
    protected $table = 'piece_type';

    protected $primaryKey = 'piece_type_id';

    public $timestamps = false;

    protected $fillable = ['category', 'name_en', 'name_ar', 'typical_min_g', 'typical_max_g', 'is_enabled'];

    protected function casts(): array
    {
        return [
            'category' => PieceCategory::class,
            'typical_min_g' => 'decimal:3',
            'typical_max_g' => 'decimal:3',
            'is_enabled' => 'boolean',
        ];
    }
}
