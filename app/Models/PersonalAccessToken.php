<?php

namespace App\Models;

use App\Support\DatabaseActor;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token, with one change for row-level security (spec 003,
 * research R3): authentication runs before any database actor is bound, so
 * the token's owner (a `customer` row, which is RLS-protected) is loaded
 * here under a short `bootstrap` elevation that ends before any application
 * code runs. Nothing else about token handling changes.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    public static function findToken($token)
    {
        return DatabaseActor::elevate('bootstrap', function () use ($token) {
            $instance = parent::findToken($token);
            $instance?->loadMissing('tokenable');

            return $instance;
        });
    }
}
