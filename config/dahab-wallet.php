<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Wallet top-ups (spec 009)
    |--------------------------------------------------------------------------
    |
    | Transfer notices a customer may file per minute (research R15). A notice
    | creates work for Finance; this bounds abuse without limiting amounts,
    | which are never limited by Dahab (daily limits are display-only).
    |
    */

    'topups_per_minute' => (int) env('DAHAB_TOPUPS_PER_MINUTE', 10),
];
