<?php

/*
 * The customer account (spec 017).
 */
return [
    // The page of the customer app that opens an email-change link (research R2).
    'email_change_url' => rtrim((string) env('CUSTOMER_APP_URL', 'http://localhost:8765'), '/').'/#/email-confirm',

    // An email-change link works once, for this many minutes (Q28: as the spec 013 link).
    'email_change_minutes' => 30,
];
