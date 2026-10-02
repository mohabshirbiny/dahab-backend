<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Buy requests (spec 011)
    |--------------------------------------------------------------------------
    | The business numbers (deposit percent, seller reply window, reach-branch
    | window, price tolerance) are settings changed from the Dashboard, never
    | here. This file only holds request plumbing.
    */

    // Buy requests sent per customer per minute (throttle customer.buy_requests).
    'send_per_minute' => 10,

    // How many queue entries a seller's queue returns at most (the whole line; research R1).
    'queue_max' => 200,

    // Staff cancellation reason length (spec 011 FR-020a).
    'cancel_reason_min' => 10,
    'cancel_reason_max' => 1000,
];
