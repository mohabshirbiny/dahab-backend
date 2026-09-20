<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customer uploads (POST /customer/me/uploads)
    |--------------------------------------------------------------------------
    | An upload is stored encrypted on the private `identity_private` disk and
    | referenced by a single-use `upload_token` that expires unclaimed after
    | `upload_token_ttl_seconds`. Images only: the reviewer UI renders them
    | inline, so nothing that could carry active content is accepted.
    */

    'upload_token_ttl_seconds' => (int) env('DAHAB_IDENTITY_UPLOAD_TOKEN_TTL_SECONDS', 3600),
    'max_upload_kb' => (int) env('DAHAB_IDENTITY_MAX_UPLOAD_KB', 8192),
    'allowed_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    'uploads_per_minute' => 10,
];
