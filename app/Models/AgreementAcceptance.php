<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The permanent record of one tick of a legal text (schema §6, spec 010):
 * who, which version, in what context, when and from where. Append-only.
 */
class AgreementAcceptance extends Model
{
    use HasUuids;

    /** `context` for the ownership declaration ticked when listing a piece. */
    public const CONTEXT_LIST_PIECE = 'list_piece';

    protected $table = 'agreement_acceptance';

    protected $primaryKey = 'acceptance_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['acceptance_id'];

    protected function casts(): array
    {
        return ['accepted_at' => 'immutable_datetime', 'legal_doc_id' => 'integer'];
    }
}
