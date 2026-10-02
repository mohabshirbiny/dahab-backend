<?php

/*
 * Orders (spec 012). Operational constants that are not business settings
 * (those live in the `setting` table and are read live).
 */
return [
    // Remind the seller when this many working hours are left to reach the branch.
    'reach_reminder_working_hours' => (int) env('DAHAB_ORDERS_REACH_REMINDER_WORKING_HOURS', 3),

    // Remind the buyer when this many clock hours are left to pay the balance.
    'balance_reminder_hours' => (int) env('DAHAB_ORDERS_BALANCE_REMINDER_HOURS', 24),

    // The Buy requests page highlights requests whose reply deadline is this close.
    'near_expiry_hours' => (int) env('DAHAB_ORDERS_NEAR_EXPIRY_HOURS', 6),

    // Wrong collection codes before the handover locks, and for how long.
    'handover_max_attempts' => 5,
    'handover_lock_minutes' => 15,

    'code_length' => 6,
];
