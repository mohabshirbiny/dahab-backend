<?php

/*
 * Finance operations (spec 015). Business tunables (the compensation caps)
 * live in the settings table; these are technical values.
 */
return [

    // The CSV exports of the compensation list, the bank book and the recorded movements.
    'export_cap' => 10000,

    // A bank movement's proof: PDF, JPG or PNG, at most 10 MB, stored encrypted.
    'proof_mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
    'proof_max_kb' => 10240,

    // Rows in the Overview's "Needs a decision".
    'overview_needs_decision_limit' => 10,
];
