<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The seller's acceptance of the ownership declaration for one piece
 * (spec 010 FR-004, table `listing_ownership_declaration`). Written once,
 * with the listing; the same acceptance is also in `agreement_acceptance`.
 */
class ListingOwnershipDeclaration extends Model
{
    protected $table = 'listing_ownership_declaration';

    protected $primaryKey = 'listing_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['accepted_at' => 'immutable_datetime', 'legal_doc_id' => 'integer'];
    }
}
