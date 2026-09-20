<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Sanctum-side revocation happens by deleting the row in
        // personal_access_tokens (see RevokeTokenFamilyAction). No further
        // authenticate-callback is required.
    }
}
