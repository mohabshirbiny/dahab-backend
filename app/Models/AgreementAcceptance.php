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

    /** `context` for the deposit terms accepted with a buy request (spec 011). */
    public const CONTEXT_BUY_REQUEST = 'buy_request';

    /** Spec 013: the payout-account declaration, ticked when adding an account. */
    public const CONTEXT_PAYOUT_ACCOUNT = 'payout_account';

    /** Spec 014: the proxy authorisation, ticked when naming someone else to collect. */
    public const CONTEXT_COLLECTION_PROXY = 'collection_proxy';

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
