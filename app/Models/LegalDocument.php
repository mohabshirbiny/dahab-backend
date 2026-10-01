<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A version of a legal text customers agree to (schema §6, spec 010). Every
 * version is kept; the current one of a code is its highest version.
 *
 * @property int $legal_doc_id
 * @property string $code
 * @property int $version
 * @property string $body_en
 * @property string $body_ar
 */
class LegalDocument extends Model
{
    /** Ticked when listing a piece (Part 2 §3). */
    public const OWNERSHIP_DECLARATION = 'ownership_declaration';

    /** Accepted with every buy request (Part 2 §4, spec 011). */
    public const DEPOSIT_AGREEMENT = 'deposit_agreement';

    protected $table = 'legal_document';

    protected $primaryKey = 'legal_doc_id';

    public $timestamps = false;

    protected $guarded = ['legal_doc_id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_material' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }

    public static function current(string $code): ?self
    {
        return self::query()->where('code', $code)->orderByDesc('version')->first();
    }
}
