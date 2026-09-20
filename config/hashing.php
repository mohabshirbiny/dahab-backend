<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | Dahab stores every password (customer and staff) as Argon2id, as required
    | by docs/Technical Spec/dahab-spec-part1-auth.md §2.1 and spec FR-X-007.
    | Do not switch this to bcrypt/argon2i; only the cost factors below are
    | meant to be tuned per environment.
    |
    | Supported: "bcrypt", "argon", "argon2id"
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    |
    | Only relevant for the legacy `bcrypt` driver. Argon2id is the default.
    |
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
        'limit' => env('BCRYPT_LIMIT', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon Options
    |--------------------------------------------------------------------------
    |
    | Memory is in KiB. The defaults (64 MiB, 4 passes, 1 lane) exceed the
    | OWASP minimum for Argon2id. Tests lower these through phpunit.xml.
    |
    | `verify` is deliberately false: FR-X-007 keeps bcrypt hashes acceptable
    | for legacy accounts. Hash::check() then falls back to password_verify(),
    | Hash::needsRehash() reports true for them, and the sign-in actions
    | transparently rewrite the hash as Argon2id on the first successful login.
    |
    */

    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 4),
        'verify' => env('HASH_VERIFY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rehash On Login
    |--------------------------------------------------------------------------
    |
    | Automatically rehash on login when the configured work factor changes.
    |
    */

    'rehash_on_login' => true,

];
