<?php

/*
 * Seller-side codes and referral (spec 023).
 *
 * Every business number lives in the `setting` table (Commission rates). The one figure kept here
 * is a request limit, like the other `dahab-*` configuration files: how many code checks and
 * Share taps one customer may make a minute, so codes cannot be enumerated.
 */
return [
    'promo_checks_per_minute' => 10,
];
