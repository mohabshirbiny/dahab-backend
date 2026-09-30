<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Listings (spec 010)
    |--------------------------------------------------------------------------
    | Media limits and what a listing needs before it can be sent for review.
    | Sizes are kilobytes. Types are checked by content, not by file name.
    | Listing media is encrypted and served in chunks, so a video is never
    | held whole in memory; PHP still needs upload_max_filesize /
    | post_max_size above the video size (docker/php/uploads.ini).
    */

    'max_photos' => 6,
    'min_photos' => [
        'gold' => 2,
        'diamond' => 3,
        'gold_with_diamond' => 3,
    ],
    'description_min' => 40,
    'description_max' => 2000,
    'decision_note_min' => 10,
    'decision_note_max' => 1000,

    'photo_max_kb' => 8192,
    'video_max_kb' => 51200,
    'document_max_kb' => 8192,

    'photo_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    'video_mimes' => ['mp4', 'mov', 'qt', 'webm'],
    'document_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],

    // Bytes read, encrypted and written at a time (IdentityDocumentStorage::storeChunkedAt).
    'chunk_bytes' => 1048576,

    'listings_per_minute' => 20,
    'market_per_minute' => 120,
];
