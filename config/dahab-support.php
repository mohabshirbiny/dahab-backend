<?php

/*
 * How customers reach Dahab (spec 017 FR-045, research R7), served by
 * GET /api/v1/reference/support-contacts and used in security alerts.
 *
 * DEMO VALUES from the customer prototype — replace before production.
 * Chat has no backend yet, so it is not listed.
 */
return [
    'phone' => env('DAHAB_SUPPORT_PHONE', '16000'),
    'hours_en' => env('DAHAB_SUPPORT_HOURS_EN', 'Sunday to Thursday, 10:00 to 18:00'),
    'hours_ar' => env('DAHAB_SUPPORT_HOURS_AR', 'من الأحد للخميس، من ١٠ الصبح لـ ٦ المغرب'),
    'whatsapp' => env('DAHAB_SUPPORT_WHATSAPP', '+201044172026'),
    'email' => env('DAHAB_SUPPORT_EMAIL', 'help@dahabapp.com'),
    'social' => [
        'facebook' => env('DAHAB_SUPPORT_FACEBOOK', 'dahabapp'),
        'instagram' => env('DAHAB_SUPPORT_INSTAGRAM', 'dahabapp'),
        'tiktok' => env('DAHAB_SUPPORT_TIKTOK', 'dahabapp'),
    ],
];
