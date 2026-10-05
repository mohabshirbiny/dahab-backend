<?php

/*
 * Tax invoices and credit notes (spec 016).
 *
 * `issuer` is Dahab's legal identity printed on every invoice and credit
 * note. The product owner chose a PHP configuration file, not environment
 * variables (spec 016 Clarifications). No document in docs/ gives the real
 * values: the ones below are DEMO values for local testing (kept on purpose by
 * the product owner, 2026-10-05) and must be replaced with Dahab's real legal
 * details before production. When any of the six is empty, payments and credit
 * notes still go through and their PDFs wait (`document_not_ready`); the
 * scheduled `invoices:render-pending` builds them once the details are
 * complete. Each document keeps the copy it was made with. Never copy these
 * values into a frontend repository.
 */
return [

    'issuer' => [
        'legal_name_en' => 'Dahab Demo Company (local test)',
        'legal_name_ar' => 'شركة دهب التجريبية (اختبار محلي)',
        'address_en' => '1 Demo Street, Nasr City, Cairo',
        'address_ar' => '١ شارع تجريبي، مدينة نصر، القاهرة',
        'tax_registration_no' => '000-000-000',
        'commercial_register_no' => '00000',
    ],

    // The CSV export of the invoices list (the platform's truncation convention).
    'export_cap' => 10000,

    // Attempts of one document job before the five-minute sweep takes over.
    'render_tries' => 5,
];
