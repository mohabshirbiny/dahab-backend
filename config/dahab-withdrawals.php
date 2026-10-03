<?php

/*
 * Withdrawals and payout accounts (spec 013). Business tunables live in the
 * settings table (`withdrawal.account_change_pause_hours`); these are
 * technical values.
 */
return [

    // The email second-check link lives this long (Part 1 §2.1 "short TTL"; Clarification: 30 minutes).
    'confirmation_ttl_minutes' => (int) env('WITHDRAWAL_CONFIRMATION_TTL_MINUTES', 30),

    // The Customer App page the email link opens (Flutter web, hash routes).
    'confirm_url' => rtrim((string) env('CUSTOMER_APP_URL', 'http://localhost:8765'), '/').'/#/withdraw-confirm',

    // "Account in use changed recently" signal on the staff queue.
    'recent_change_days' => 30,

    // The CSV export of the staff list (spec 009 pattern).
    'export_cap' => 50000,

    // How many ended pauses one sweep run announces.
    'sweep_batch' => 1000,
];
