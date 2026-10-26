<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * Generates the OpenAPI document into a throwaway directory (never
 * storage/api-docs) and returns it decoded.
 */
function generatedOpenApi(): array
{
    static $doc = null;

    if ($doc === null) {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dahab-openapi-'.uniqid();
        File::ensureDirectoryExists($dir);
        config(['l5-swagger.defaults.paths.docs' => $dir]);

        expect(Artisan::call('l5-swagger:generate'))->toBe(0);

        $json = $dir.DIRECTORY_SEPARATOR.'api-docs.json';
        expect(file_exists($json))->toBeTrue();

        $doc = json_decode(file_get_contents($json), true, flags: JSON_THROW_ON_ERROR);
        $doc['__raw'] = file_get_contents($json);
        File::deleteDirectory($dir);
    }

    return $doc;
}

const OPENAPI_CUSTOMER_PATHS = [
    'post /customer/auth/register/start',
    'post /customer/auth/register/verify-phone-otp',
    'post /customer/auth/register/email',
    'post /customer/auth/register/verify-email-otp',
    'post /customer/auth/register/documents',
    'post /customer/auth/register/submit',
    'post /customer/auth/register/complete',
    'post /customer/auth/login',
    'post /customer/auth/refresh',
    'get /customer/auth/me',
    'post /customer/auth/logout',
    'post /customer/auth/logout-all',
    'post /customer/auth/otp/verify',
    'post /customer/auth/otp/resend',
    // Spec 020: a forgotten password.
    'post /customer/auth/password/reset-request',
    'post /customer/auth/password/reset-confirm',
];

const OPENAPI_DASHBOARD_PATHS = [
    'post /dashboard/auth/login',
    'post /dashboard/auth/mfa/verify',
    'post /dashboard/auth/mfa/enroll',
    'post /dashboard/auth/refresh',
    'get /dashboard/auth/me',
    'post /dashboard/auth/logout',
    'post /dashboard/auth/logout-all',
    // Spec 020: staff recovery.
    'post /dashboard/auth/password/reset-request',
    'post /dashboard/auth/password/reset-confirm',
];

const OPENAPI_CUSTOMER_IDENTITY_PATHS = [
    'post /customer/me/uploads',
    'post /customer/me/identity-documents',
];

const OPENAPI_DASHBOARD_IDENTITY_PATHS = [
    'get /dashboard/identity-documents',
    'get /dashboard/identity-documents/{document}',
    'get /dashboard/identity-documents/{document}/image',
    'post /dashboard/identity-documents/{document}/review',
    'post /dashboard/identity-documents/{document}/reopen',
];

const OPENAPI_DASHBOARD_CUSTOMER_PATHS = [
    'get /dashboard/customers',
    'get /dashboard/customers/{customer}',
    // Spec 007: the Customer file.
    'post /dashboard/customers/{customer}/suspend',
    'post /dashboard/customers/{customer}/reinstate',
    'post /dashboard/customers/{customer}/type',
    'get /dashboard/customers/{customer}/activity',
    'get /dashboard/customers/{customer}/sessions',
];

// Spec 002: roles, permissions and staff role assignment.
const OPENAPI_DASHBOARD_ACCESS_PATHS = [
    'get /dashboard/permissions',
    'get /dashboard/roles',
    'post /dashboard/roles',
    'get /dashboard/roles/{role}',
    'patch /dashboard/roles/{role}',
    'delete /dashboard/roles/{role}',
    'get /dashboard/staff',
    'get /dashboard/staff/{staff}',
    'put /dashboard/staff/{staff}/roles',
    'put /dashboard/staff/{staff}/branch',
    // Spec 020: create, disable and enable.
    'post /dashboard/staff',
    'post /dashboard/staff/{staff}/disable',
    'post /dashboard/staff/{staff}/enable',
];

const OPENAPI_DASHBOARD_REFERENCE_PATHS = [
    'get /dashboard/karats',
    'post /dashboard/karats',
    'post /dashboard/karats/{code}/toggle',
    'get /dashboard/branches',
    'post /dashboard/branches',
    'patch /dashboard/branches/{branch}',
    'get /dashboard/branch-closures',
    'post /dashboard/branch-closures',
    'delete /dashboard/branch-closures/{closure}',
];

const OPENAPI_DASHBOARD_PRICING_PATHS = [
    'get /dashboard/settings',
    'patch /dashboard/settings/{key}',
    'get /dashboard/settings/history',
    'get /dashboard/gold-prices',
    'get /dashboard/gold-prices/current',
    'post /dashboard/gold-prices/preview',
    'post /dashboard/gold-prices/manual',
    'post /dashboard/gold-prices/manual/{manualPrice}/confirm',
    'put /dashboard/karats/{code}/adjustments',
    'get /dashboard/price-adjustments/history',
];

const OPENAPI_DASHBOARD_AUDIT_PATHS = [
    'get /dashboard/audit-log',
    'get /dashboard/audit-log/categories',
    'get /dashboard/audit-log/export',
    'get /dashboard/audit-log/{entry}',
];

// Spec 008: wallet reads.
const OPENAPI_CUSTOMER_WALLET_PATHS = [
    'get /customer/me/wallet',
    'get /customer/me/wallet/transactions',
    // spec 015: what each buy request and order holds.
    'get /customer/me/wallet/held',
    // Package B: the caller's own wallet statement PDF.
    'get /customer/me/wallet/statement.pdf',
];

const OPENAPI_DASHBOARD_WALLET_PATHS = [
    'get /dashboard/wallets/overview',
    'get /dashboard/customers/{customer}/wallet',
    'get /dashboard/wallet-statement',
    'get /dashboard/wallet-statement/export',
];

// Spec 009: wallet top-up.
const OPENAPI_CUSTOMER_TOPUP_PATHS = [
    'get /customer/me/wallet/topup-methods',
    'get /customer/me/wallet/topups',
    'post /customer/me/wallet/topups',
    'post /customer/me/wallet/topups/{topup}/cancel',
];

const OPENAPI_DASHBOARD_TOPUP_PATHS = [
    'get /dashboard/topups',
    'post /dashboard/topups',
    'get /dashboard/topups/export',
    'get /dashboard/topups/{topup}',
    'get /dashboard/topups/{topup}/receipt',
    'post /dashboard/topups/{topup}/match',
    'post /dashboard/topups/{topup}/hold',
    'post /dashboard/topups/{topup}/unhold',
    'post /dashboard/topups/{topup}/reject',
    'get /dashboard/receiving-accounts',
    'post /dashboard/receiving-accounts',
    'patch /dashboard/receiving-accounts/{account}',
];

// Spec 010: the seller's listings, the staff review queue, and the public surface
// (market + reference), which needs no token.
const OPENAPI_CUSTOMER_LISTING_PATHS = [
    'get /customer/me/listings',
    'post /customer/me/listings',
    'get /customer/me/listings/{listing}',
    'patch /customer/me/listings/{listing}',
    'get /customer/me/listings/{listing}/media/{media}',
    'post /customer/me/listings/{listing}/submit',
    'post /customer/me/listings/{listing}/withdraw',
    'post /customer/me/listings/{listing}/relist',
    'post /customer/me/listings/{listing}/price', // spec 022
    // Spec 023: codes on the sell form, referral and commission credits.
    'post /customer/me/commission-preview',
    'put /customer/me/listings/{listing}/promo',
    'delete /customer/me/listings/{listing}/promo',
    'get /customer/me/referral',
    'post /customer/me/referral/shares',
    'get /customer/me/commission-credits',
];

// spec 011: the buyer's requests and the seller's queue.
const OPENAPI_CUSTOMER_BUY_REQUEST_PATHS = [
    'get /customer/me/listings/{listing}/buy-requests',
    'post /customer/me/listings/{listing}/accept',
    'post /customer/me/listings/{listing}/decline',
    'get /customer/me/buy-requests',
    'post /customer/me/buy-requests',
    'post /customer/me/buy-requests/promo-preview',
    'get /customer/me/buy-requests/{buyRequest}',
    'post /customer/me/buy-requests/{buyRequest}/withdraw',
];

const OPENAPI_DASHBOARD_ORDER_PATHS = [
    'post /dashboard/orders/{order}/cancel',
    // spec 015: the list as CSV.
    'get /dashboard/orders/export',
    // spec 012: the order's life after acceptance, staff side.
    'get /dashboard/orders/{order}',
    'post /dashboard/orders/{order}/receive',
    'post /dashboard/orders/{order}/inspection-results',
    'post /dashboard/orders/{order}/propose-price',
    'post /dashboard/orders/{order}/seller-return/handover',
    'get /dashboard/inspections/work-list',
    'post /dashboard/orders/{order}/handover',
    'post /dashboard/orders/{order}/change-branch',
    'post /dashboard/orders/{order}/extend-deadline',
    'get /dashboard/orders',
    'get /dashboard/inspections',
    'get /dashboard/buy-requests',
    // spec 014: sellers' requests for more time, the proxy's ID.
    'get /dashboard/extension-requests',
    'post /dashboard/extension-requests/{extensionRequest}/accept',
    'post /dashboard/extension-requests/{extensionRequest}/refuse',
    'get /dashboard/orders/{order}/buyer-id',
    'get /dashboard/orders/{order}/certificates/{media}',
    'get /dashboard/orders/{order}/proxy-id',
];

// spec 014: the Disputes page.
const OPENAPI_DASHBOARD_DISPUTE_PATHS = [
    'get /dashboard/disputes',
    'get /dashboard/disputes/{dispute}',
    'get /dashboard/disputes/{dispute}/photos/{photo}',
    'get /dashboard/dispute-assignees',
    'post /dashboard/disputes/{dispute}/pass-on',
    'post /dashboard/disputes/{dispute}/resolve',
];

// spec 012: the buyer's and the seller's orders.
const OPENAPI_CUSTOMER_ORDER_PATHS = [
    'get /customer/me/orders',
    'get /customer/me/orders/{order}',
    'get /customer/me/orders/{order}/media/{media}',
    'post /customer/me/orders/{order}/cancel',
    'post /customer/me/orders/{order}/decision',
    'post /customer/me/orders/{order}/pay-balance',
    'post /customer/me/orders/{order}/relist',
    // spec 014: report a problem, ask for more time, someone else collects.
    'post /customer/me/orders/{order}/disputes',
    'post /customer/me/orders/{order}/extension-requests',
    'post /customer/me/orders/{order}/proxy',
    'post /customer/me/orders/{order}/proxy/remove',
    'post /customer/me/orders/{order}/free-relist',
    'post /customer/me/orders/{order}/rating',
];

// spec 013: payout accounts and withdrawals, the staff queue, and the email link's public page.
const OPENAPI_CUSTOMER_WITHDRAWAL_PATHS = [
    'get /customer/me/payout-accounts',
    'post /customer/me/payout-accounts',
    'post /customer/me/payout-accounts/{account}/use',
    'post /customer/me/payout-accounts/{account}/remove',
    'post /customer/me/payout-accounts/{account}/keep',
    'get /customer/me/withdrawals',
    'post /customer/me/withdrawals',
    'get /customer/me/withdrawals/{withdrawal}',
    'post /customer/me/withdrawals/confirmations',
    'get /customer/me/withdrawals/confirmations/{confirmation}',
    'post /customer/me/withdrawals/{withdrawal}/cancel',
];

const OPENAPI_DASHBOARD_WITHDRAWAL_PATHS = [
    'get /dashboard/payout-accounts',
    'post /dashboard/payout-accounts/{account}/verify',
    'post /dashboard/payout-accounts/{account}/refuse',
    'get /dashboard/withdrawals',
    'get /dashboard/withdrawals/export',
    'get /dashboard/withdrawals/{withdrawal}',
    'post /dashboard/withdrawals/{withdrawal}/review',
    'post /dashboard/withdrawals/{withdrawal}/hold',
    'post /dashboard/withdrawals/{withdrawal}/unhold',
    'post /dashboard/withdrawals/{withdrawal}/release',
    'post /dashboard/withdrawals/{withdrawal}/reject',
];

const OPENAPI_WITHDRAWAL_CONFIRMATION_PATHS = [
    'post /withdrawal-confirmations/read',
    'post /withdrawal-confirmations/confirm',
];

const OPENAPI_DASHBOARD_LISTING_PATHS = [
    'get /dashboard/listings',
    'get /dashboard/listings/{listing}',
    'get /dashboard/listings/{listing}/media/{media}',
    'post /dashboard/listings/{listing}/approve',
    'post /dashboard/listings/{listing}/request-changes',
    'post /dashboard/listings/{listing}/reject',
    'post /dashboard/listings/{listing}/takedown',
    'post /dashboard/listings/{listing}/relist',
];

const OPENAPI_MARKET_PATHS = [
    'get /market/listings',
    'get /market/listings/{listing}',
    'get /market/listings/{listing}/media/{media}',
];

const OPENAPI_REFERENCE_PATHS = [
    'get /reference/karats',
    'get /reference/piece-types',
    'get /reference/branches',
    'get /reference/legal-documents/{code}',
    // spec 015: today's prices and the seller's quote.
    'get /reference/gold-prices',
    'get /reference/quote',
    // the stone certificate costs shown on the sell form.
    'get /reference/certificate-costs',
];

// spec 015: finance operations.
const OPENAPI_DASHBOARD_FINANCE_PATHS = [
    'get /dashboard/compensation',
    'post /dashboard/compensation',
    'get /dashboard/compensation/export',
    'get /dashboard/compensation/orders',
    'post /dashboard/customers/{customer}/wallet-adjustments',
    'get /dashboard/wallet-adjustments',
    'post /dashboard/uploads',
    'get /dashboard/bank-movements',
    'post /dashboard/bank-movements',
    'get /dashboard/bank-movements/export',
    'get /dashboard/bank-movements/{movement}/proof',
    'get /dashboard/bank-book',
    'get /dashboard/bank-book/export',
    'get /dashboard/daily-close',
    'post /dashboard/daily-close',
    'get /dashboard/daily-closes',
];

const OPENAPI_DASHBOARD_OVERVIEW_PATHS = [
    'get /dashboard/overview',
];

// Spec 016: tax invoices and credit notes.
const OPENAPI_INVOICE_PATHS = [
    'get /customer/me/invoices',
    'get /customer/me/invoices/{invoice}',
    'get /customer/me/invoices/{invoice}/pdf',
    'get /customer/me/credit-notes/{creditNote}/pdf',
    'get /dashboard/invoices',
    'get /dashboard/invoices/export',
    'get /dashboard/invoices/{invoice}',
    'get /dashboard/invoices/{invoice}/pdf',
    'post /dashboard/invoices/{invoice}/credit-notes',
    'get /dashboard/credit-notes',
    'get /dashboard/credit-notes/{creditNote}/pdf',
];

// Spec 019: App text (content.edit) and the public bundle.
const OPENAPI_CONTENT_PATHS = [
    'get /reference/app-texts',
    'get /dashboard/app-texts',
    'get /dashboard/app-texts/export',
    'put /dashboard/app-texts/{key}/draft',
    'delete /dashboard/app-texts/{key}/draft',
    'post /dashboard/app-texts/{key}/revert',
    'post /dashboard/app-texts/publish',
    'get /dashboard/app-texts/{key}/history',
    'get /dashboard/content/placeholders',
];

// Spec 017: the customer account, the inbox, saved pieces, closing, listing reports.
const OPENAPI_ACCOUNT_PATHS = [
    'post /customer/me/phone-change',
    'post /customer/me/phone-change/{challenge}/confirm',
    'post /customer/me/email-change',
    'post /customer/me/password',
    'get /customer/me/sessions',
    'post /customer/me/sessions/{session}/sign-out',
    'get /customer/me/notifications',
    'get /customer/me/notifications/unread-count',
    'post /customer/me/notifications/{notification}/read',
    'post /customer/me/notifications/read-all',
    'get /customer/me/saved-pieces',
    'post /customer/me/saved-pieces',
    'delete /customer/me/saved-pieces/{listing}',
    'get /customer/me/account/close-check',
    'post /customer/me/account/close',
    'post /customer/me/listing-reports',
    'post /contact-changes/email/read',
    'post /contact-changes/email/confirm',
    'get /reference/legal-documents',
    'get /reference/support-contacts',
    'get /dashboard/listing-reports',
    'get /dashboard/listing-reports/{report}',
    'post /dashboard/listing-reports/{report}/dismiss',
    'post /dashboard/listing-reports/{report}/take-down',
    'get /dashboard/customers/{customer}/notifications',
];

// Spec 021: founder-account security (2 public auth routes, 7 founder routes) and the notices (3).
const OPENAPI_FOUNDER_SECURITY_PUBLIC_PATHS = [
    'post /dashboard/auth/device-approval/status',
    'post /dashboard/auth/frozen/confirm-unfreeze',
    // Settings page and relist: the new-device SMS code of a staff sign-in.
    'post /dashboard/auth/device-code/verify',
    'post /dashboard/auth/device-code/resend',
];

const OPENAPI_FOUNDER_SECURITY_PATHS = [
    'get /dashboard/founder/device-approvals',
    'post /dashboard/founder/device-approvals/{approval}/approve',
    'post /dashboard/founder/device-approvals/{approval}/refuse',
    'get /dashboard/founder/devices',
    'post /dashboard/founder/freeze',
    'get /dashboard/founder/freezes',
    'post /dashboard/founder/unfreeze',
];

const OPENAPI_NOTICE_PATHS = [
    'get /dashboard/notices',
    'post /dashboard/notices/{notice}/read',
    'post /dashboard/notices/read-all',
];

function documentedOperations(array $doc): array
{
    $ops = [];
    foreach ($doc['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            $ops[strtolower($method).' '.$path] = $operation;
        }
    }

    return $ops;
}

it('generates a valid OpenAPI document', function () {
    $doc = generatedOpenApi();

    expect($doc['openapi'])->toStartWith('3.')
        ->and($doc['info']['title'])->toBe('Dahab API')
        ->and($doc['servers'][0]['url'])->toBe('/api/v1');
});

// Spec 019 later phases: legal publishing, Help, Switches, market makers, People to watch.
const OPENAPI_SPEC019_LATER_PATHS = [
    'post /customer/me/legal-acceptances',
    'get /customer/me/market/listings',
    'post /dashboard/faq',
    'patch /dashboard/faq/{slug}/position',
    'get /dashboard/category-controls',
    'post /dashboard/category-controls',
    'post /dashboard/category-controls/clear',
    'get /dashboard/visibility',
    'patch /dashboard/visibility/{key}',
    'get /dashboard/legal-documents',
    'get /dashboard/legal-documents/{code}/versions',
    'post /dashboard/legal-documents/{code}/versions',
    'get /dashboard/promo-codes',
    'post /dashboard/promo-codes',
    'patch /dashboard/promo-codes/{code}',
    'put /dashboard/promo-codes/{code}',
    'delete /dashboard/promo-codes/{code}',
    'get /dashboard/promo-codes/{code}/uses',
    'get /dashboard/market-maker/queue',
    'post /dashboard/market-maker/queue/{listing}/leave', // spec 023
    'get /dashboard/market-maker/queue/{listing}/media/{media}', // spec 023
    'post /dashboard/customers/{customer}/first-sale-advance', // spec 023
    'delete /dashboard/customers/{customer}/first-sale-advance', // spec 023
    'get /dashboard/first-sale-advances', // spec 023
    'get /dashboard/market-maker/approvals',
    'post /dashboard/market-maker/approvals',
    'get /dashboard/market-maker/cost',
    'get /dashboard/people-to-watch',
    'get /dashboard/people-to-watch/export',
    'get /reference/controls',
];

it('documents exactly the customer, dashboard and public endpoints', function () {
    $ops = documentedOperations(generatedOpenApi());

    expect(array_keys($ops))->toEqualCanonicalizing([
        ...OPENAPI_CUSTOMER_PATHS,
        ...OPENAPI_DASHBOARD_PATHS,
        ...OPENAPI_CUSTOMER_IDENTITY_PATHS,
        ...OPENAPI_SPEC019_LATER_PATHS,
        ...OPENAPI_DASHBOARD_IDENTITY_PATHS,
        ...OPENAPI_DASHBOARD_CUSTOMER_PATHS,
        ...OPENAPI_DASHBOARD_ACCESS_PATHS,
        ...OPENAPI_DASHBOARD_REFERENCE_PATHS,
        ...OPENAPI_DASHBOARD_PRICING_PATHS,
        ...OPENAPI_DASHBOARD_AUDIT_PATHS,
        ...OPENAPI_CUSTOMER_WALLET_PATHS,
        ...OPENAPI_DASHBOARD_WALLET_PATHS,
        ...OPENAPI_CUSTOMER_TOPUP_PATHS,
        ...OPENAPI_DASHBOARD_TOPUP_PATHS,
        ...OPENAPI_CUSTOMER_LISTING_PATHS,
        ...OPENAPI_DASHBOARD_LISTING_PATHS,
        ...OPENAPI_CUSTOMER_BUY_REQUEST_PATHS,
        ...OPENAPI_DASHBOARD_ORDER_PATHS,
        ...OPENAPI_CUSTOMER_ORDER_PATHS,
        ...OPENAPI_DASHBOARD_DISPUTE_PATHS,
        ...OPENAPI_CUSTOMER_WITHDRAWAL_PATHS,
        ...OPENAPI_DASHBOARD_WITHDRAWAL_PATHS,
        ...OPENAPI_WITHDRAWAL_CONFIRMATION_PATHS,
        ...OPENAPI_MARKET_PATHS,
        ...OPENAPI_REFERENCE_PATHS,
        ...OPENAPI_DASHBOARD_FINANCE_PATHS,
        ...OPENAPI_DASHBOARD_OVERVIEW_PATHS,
        ...OPENAPI_ACCOUNT_PATHS,
        ...OPENAPI_INVOICE_PATHS,
        ...OPENAPI_CONTENT_PATHS,
        ...OPENAPI_FOUNDER_SECURITY_PUBLIC_PATHS,
        ...OPENAPI_FOUNDER_SECURITY_PATHS,
        ...OPENAPI_NOTICE_PATHS,
    ]);
});

it('no longer documents the generic /auth/* endpoints or /user', function () {
    $paths = array_keys(generatedOpenApi()['paths']);

    foreach ($paths as $path) {
        expect($path)->not->toStartWith('/auth/')->and($path)->not->toBe('/user');
    }
});

it('defines separate security schemes for the customer and dashboard surfaces', function () {
    $schemes = generatedOpenApi()['components']['securitySchemes'];

    expect(array_keys($schemes))->toEqualCanonicalizing([
        'customerBearer', 'customerRefreshBearer', 'dashboardBearer', 'dashboardRefreshBearer',
    ]);

    foreach ($schemes as $scheme) {
        expect($scheme['type'])->toBe('http')->and($scheme['scheme'])->toBe('bearer');
    }
});

it('secures every operation with its own surface scheme and never the generic sanctum one', function () {
    $ops = documentedOperations(generatedOpenApi());

    $expected = [
        'post /customer/auth/register/start' => null,
        'post /customer/auth/register/verify-phone-otp' => null,
        'post /customer/auth/register/email' => null,
        'post /customer/auth/register/verify-email-otp' => null,
        'post /customer/auth/register/documents' => null,
        'post /customer/auth/register/submit' => null,
        'post /customer/auth/register/complete' => null,
        'post /customer/auth/login' => null,
        'post /customer/auth/refresh' => 'customerRefreshBearer',
        'get /customer/auth/me' => 'customerBearer',
        'post /customer/auth/logout' => 'customerBearer',
        'post /customer/auth/logout-all' => 'customerBearer',
        'post /customer/auth/otp/verify' => null,
        'post /customer/auth/otp/resend' => null,
        'post /customer/auth/password/reset-request' => null,
        'post /customer/auth/password/reset-confirm' => null,
        'post /dashboard/auth/password/reset-request' => null,
        'post /dashboard/auth/password/reset-confirm' => null,
        'post /dashboard/auth/login' => null,
        'post /dashboard/auth/mfa/verify' => null,
        'post /dashboard/auth/mfa/enroll' => null,
        'post /dashboard/auth/refresh' => 'dashboardRefreshBearer',
        'get /dashboard/auth/me' => 'dashboardBearer',
        'post /dashboard/auth/logout' => 'dashboardBearer',
        'post /dashboard/auth/logout-all' => 'dashboardBearer',
        'post /customer/me/uploads' => 'customerBearer',
        'post /customer/me/identity-documents' => 'customerBearer',
        'get /dashboard/identity-documents' => 'dashboardBearer',
        'get /dashboard/identity-documents/{document}' => 'dashboardBearer',
        'get /dashboard/identity-documents/{document}/image' => 'dashboardBearer',
        'post /dashboard/identity-documents/{document}/review' => 'dashboardBearer',
        'post /dashboard/identity-documents/{document}/reopen' => 'dashboardBearer',
        'get /dashboard/customers' => 'dashboardBearer',
        'get /dashboard/customers/{customer}' => 'dashboardBearer',
        'post /dashboard/customers/{customer}/suspend' => 'dashboardBearer',
        'post /dashboard/customers/{customer}/reinstate' => 'dashboardBearer',
        'post /dashboard/customers/{customer}/type' => 'dashboardBearer',
        'get /dashboard/customers/{customer}/activity' => 'dashboardBearer',
        'get /dashboard/customers/{customer}/sessions' => 'dashboardBearer',
        ...array_fill_keys(OPENAPI_DASHBOARD_ACCESS_PATHS, 'dashboardBearer'),
        ...array_fill_keys(OPENAPI_DASHBOARD_REFERENCE_PATHS, 'dashboardBearer'),
        ...array_fill_keys(OPENAPI_DASHBOARD_PRICING_PATHS, 'dashboardBearer'),
        ...array_fill_keys(OPENAPI_DASHBOARD_AUDIT_PATHS, 'dashboardBearer'),
        ...array_fill_keys(OPENAPI_CUSTOMER_WALLET_PATHS, 'customerBearer'),
        ...array_fill_keys(OPENAPI_DASHBOARD_WALLET_PATHS, 'dashboardBearer'),
        ...array_fill_keys(OPENAPI_CUSTOMER_TOPUP_PATHS, 'customerBearer'),
        ...array_fill_keys(OPENAPI_DASHBOARD_TOPUP_PATHS, 'dashboardBearer'),
        ...array_fill_keys(OPENAPI_CUSTOMER_LISTING_PATHS, 'customerBearer'),
        ...array_fill_keys(OPENAPI_DASHBOARD_LISTING_PATHS, 'dashboardBearer'),
        ...array_fill_keys(OPENAPI_CUSTOMER_BUY_REQUEST_PATHS, 'customerBearer'),
        ...array_fill_keys(OPENAPI_DASHBOARD_ORDER_PATHS, 'dashboardBearer'),
        ...array_fill_keys(OPENAPI_CUSTOMER_ORDER_PATHS, 'customerBearer'),
        // The public surface (spec 010): no token.
        ...array_fill_keys(OPENAPI_MARKET_PATHS, null),
        ...array_fill_keys(OPENAPI_REFERENCE_PATHS, null),
    ];

    foreach ($expected as $key => $scheme) {
        $security = $ops[$key]['security'] ?? [];

        if ($scheme === null) {
            expect($security)->toBe([], "{$key} is public");

            continue;
        }

        expect($security)->toBe([[$scheme => []]], "{$key} must use {$scheme}");
    }
});

it('tags each surface separately', function () {
    $ops = documentedOperations(generatedOpenApi());

    foreach (OPENAPI_CUSTOMER_PATHS as $key) {
        expect($ops[$key]['tags'])->toBe(['Customer Auth']);
    }
    foreach (OPENAPI_DASHBOARD_PATHS as $key) {
        expect($ops[$key]['tags'])->toBe(['Dashboard Auth']);
    }
    foreach (OPENAPI_CUSTOMER_IDENTITY_PATHS as $key) {
        expect($ops[$key]['tags'])->toBe(['Customer Identity']);
    }
    foreach (OPENAPI_DASHBOARD_IDENTITY_PATHS as $key) {
        expect($ops[$key]['tags'])->toBe(['Dashboard Identity']);
    }
    foreach (OPENAPI_DASHBOARD_CUSTOMER_PATHS as $key) {
        expect($ops[$key]['tags'])->toBe(['Dashboard Customers']);
    }
    foreach (OPENAPI_DASHBOARD_ACCESS_PATHS as $key) {
        expect($ops[$key]['tags'])->toBe(['Dashboard Access Control']);
    }

    foreach (OPENAPI_DASHBOARD_REFERENCE_PATHS as $key) {
        expect($ops[$key]['tags'])->toBe(['Dashboard Reference Data']);
    }

    foreach (OPENAPI_DASHBOARD_PRICING_PATHS as $key) {
        expect($ops[$key]['tags'])->toBe(['Dashboard Pricing']);
    }

    foreach (OPENAPI_DASHBOARD_AUDIT_PATHS as $key) {
        expect($ops[$key]['tags'])->toBe(['Dashboard Audit']);
    }

    foreach (OPENAPI_CUSTOMER_WALLET_PATHS as $key) {
        expect($ops[$key]['tags'])->toBe(['Customer Wallet']);
    }

    foreach (OPENAPI_DASHBOARD_WALLET_PATHS as $key) {
        expect($ops[$key]['tags'])->toBe(['Dashboard Wallets']);
    }

    foreach (OPENAPI_CUSTOMER_TOPUP_PATHS as $key) {
        expect($ops[$key]['tags'])->toBe(['Customer Wallet']);
    }

    foreach ([
        'Customer Listings' => OPENAPI_CUSTOMER_LISTING_PATHS,
        'Dashboard Listings' => OPENAPI_DASHBOARD_LISTING_PATHS,
        'Customer Buy Requests' => OPENAPI_CUSTOMER_BUY_REQUEST_PATHS,
        'Dashboard Orders' => OPENAPI_DASHBOARD_ORDER_PATHS,
        'Customer Orders' => OPENAPI_CUSTOMER_ORDER_PATHS,
        'Dashboard Disputes' => OPENAPI_DASHBOARD_DISPUTE_PATHS,
        'Dashboard Finance' => OPENAPI_DASHBOARD_FINANCE_PATHS,
        'Dashboard Overview' => OPENAPI_DASHBOARD_OVERVIEW_PATHS,
        'Market' => OPENAPI_MARKET_PATHS,
        'Reference' => OPENAPI_REFERENCE_PATHS,
    ] as $tag => $paths) {
        foreach ($paths as $key) {
            expect($ops[$key]['tags'])->toBe([$tag]);
        }
    }

    foreach (OPENAPI_DASHBOARD_TOPUP_PATHS as $key) {
        expect($ops[$key]['tags'])->toBe(['Dashboard Top-ups']);
    }
});

it('documents the request bodies and response schemas the endpoints use', function () {
    $doc = generatedOpenApi();

    expect(array_keys($doc['components']['schemas']))->toEqualCanonicalizing([
        'ApiError', 'CustomerProfile', 'StaffProfile', 'Session', 'LoginCustomerRequest',
        'StartRegistrationRequest', 'VerifyRegistrationOtpRequest',
        'StartEmailVerificationRequest', 'VerifyEmailOtpRequest',
        'UploadRegistrationDocumentRequest', 'SubmitRegistrationRequest',
        'LoginStaffRequest', 'StaffMfaVerifyRequest', 'StaffMfaEnrollRequest', 'StaffMfaChallenge', 'StaffMfaEnrollmentPending',
        'CreateUploadRequest', 'SubmitIdentityDocumentRequest', 'IdentityDocument',
        'ReviewIdentityDocumentRequest', 'ReopenIdentityDocumentRequest', 'StaffIdentityDocument', 'StaffCustomerVerification',
        'CustomerOtpChallenge', 'VerifyLoginOtpRequest', 'ResendLoginOtpRequest',
        'DashboardPermission', 'DashboardRole', 'DashboardRoleRef', 'DashboardStaffMember',
        'DashboardStoreRole', 'DashboardUpdateRole', 'DashboardDeleteRole', 'DashboardSetStaffRoles',
        'PasswordResetRequestRequest', 'PasswordResetConfirmRequest', 'StaffResetRequestRequest', 'StaffResetConfirmRequest',
        'DashboardCreateStaff', 'DashboardStaffStatusChange',
        'DashboardSetStaffBranch', 'DashboardKarat', 'DashboardStoreKarat', 'DashboardBranch', 'DashboardBranchHour',
        'DashboardStoreBranch', 'DashboardUpdateBranch', 'DashboardBranchClosure', 'DashboardStoreBranchClosure',
        'DashboardStaffRef', 'DashboardManualPrice', 'DashboardEnterManualPrice', 'DashboardGoldPrice', 'DashboardKaratPrice',
        'DashboardAdjustment', 'DashboardUpdateKaratAdjustments', 'DashboardPreviewPrices', 'DashboardSetting',
        'DashboardSettingChange', 'DashboardUpdateSetting', 'DashboardAdjustmentChange',
        'DashboardAuditEntry', 'DashboardAuditEntryDetail',
        'StaffCustomerFile', 'StaffCustomerFileDocument', 'StaffCustomerSuspension',
        'DashboardSuspendCustomer', 'DashboardReinstateCustomer', 'StaffCustomerSession', 'StaffCustomerDevice',
        'CustomerWallet', 'CustomerWalletMovement',
        'CustomerTopUp', 'CustomerReceivingAccount', 'SubmitTopUpNoticeRequest',
        'StaffTopUp', 'StaffReceivingAccount', 'DashboardMatchTopUp', 'DashboardHoldTopUp', 'DashboardRejectTopUp',
        'DashboardCreditTopUpByHand', 'DashboardStoreReceivingAccount', 'DashboardUpdateReceivingAccount',
        'ListingMedia', 'MarketListing', 'MarketListingDetail', 'CustomerListing', 'DashboardListing',
        'StoreListingRequest', 'UpdateListingRequest', 'ChangeListingPriceRequest', 'RequestListingChangesRequest', 'ListingReasonRequest',
        'BuyRequest', 'SellerQueueItem', 'OrderSummary', 'OrderBranch', 'SendBuyRequestRequest', 'LeaveQueueRequest',
        'AcceptBuyRequestRequest', 'DeclineBuyRequestRequest', 'CancelOrderRequest',
        'CustomerOrder', 'StaffOrder', 'InspectionResult', 'WorkListItem',
        'RecordInspectionResultRequest', 'ProposePriceRequest', 'HandoverRequest',
        'DecideAdjustmentRequest', 'ChangeBranchRequest', 'ExtendDeadlineRequest', 'StaffBuyRequest',
        'CustomerPayoutAccount', 'CustomerPayoutAccounts', 'CustomerWithdrawal', 'WithdrawalConfirmation',
        'PublicWithdrawalConfirmation', 'StaffPayoutAccount', 'StaffWithdrawal',
        'AddPayoutAccountRequest', 'RequestWithdrawalConfirmationRequest', 'SubmitWithdrawalRequest',
        'WithdrawalConfirmationTokenRequest', 'RefusePayoutAccountRequest', 'HoldWithdrawalRequest',
        'ReleaseWithdrawalRequest', 'RejectWithdrawalRequest',
        'CustomerDispute', 'StaffDispute', 'StaffExtensionRequest',
        'OpenDisputeRequest', 'RequestMoreTimeRequest', 'NameProxyRequest', 'PassOnDisputeRequest', 'ResolveDisputeRequest',
        'AcceptExtensionRequestRequest', 'RefuseExtensionRequestRequest',
        'FreeRelistRequest', 'RateOrderRequest',
        'StaffCompensation', 'PayCompensationRequest', 'StaffWalletAdjustment', 'AdjustWalletRequest',
        'StaffBankMovement', 'RecordBankMovementRequest', 'StaffDailyClose', 'CloseDayRequest',
        'StaffInvoice', 'StaffCreditNote', 'InvoiceFigures', 'IssueCreditNoteRequest', 'CustomerInvoice', 'CustomerCreditNote',
        'CustomerSession', 'CustomerVerificationState', 'RequestPhoneChangeRequest', 'ConfirmPhoneChangeRequest', 'RequestEmailChangeRequest', 'ChangePasswordRequest',
        'InboxItem', 'SavedPiece', 'CloseBlocker', 'StaffListingReport',
        'StaffLegalDocument', 'StaffLegalVersion', 'AcceptLegalVersionRequest', 'LegalAcceptanceRequired', 'DashboardCreateFaqEntry', 'DashboardMoveFaqEntry', 'DashboardPublishLegalVersion', 'DashboardClearCategoryControl', 'DashboardSetCategoryControl', 'DashboardSetVisibility', 'DashboardSetCustomerType',
        'StaffAppText', 'StaffAppTextVersion', 'StaffAppTextDraft', 'DashboardSaveAppTextDraft', 'DashboardRevertAppText',
        // Spec 021: founder-account security and the notices.
        'StaffDeviceApprovalPending', 'StaffDeviceApprovalStatusRequest', 'StaffFrozenConfirmUnfreezeRequest',
        'StaffDeviceCodePending', 'StaffDeviceCodeVerifyRequest', 'StaffDeviceCodeResendRequest',
        'DashboardDeviceApproval', 'DashboardDeviceApprovalDecision', 'DashboardFounderDevices',
        'DashboardAccountFreeze', 'DashboardFreezeConfirmation', 'DashboardFreezeFounder', 'DashboardConfirmUnfreeze',
        'DashboardStaffNotice',
    ]);

    $ops = documentedOperations($doc);
    expect($ops['post /customer/auth/register/start']['requestBody']['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/StartRegistrationRequest')
        ->and($ops['post /customer/auth/register/verify-phone-otp']['requestBody']['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/VerifyRegistrationOtpRequest')
        ->and($ops['post /customer/auth/register/submit']['requestBody']['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/SubmitRegistrationRequest')
        ->and($ops['post /customer/auth/login']['requestBody']['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/LoginCustomerRequest');

    // Refresh and access endpoints both document the 403 an ability mismatch produces.
    foreach (['post /customer/auth/refresh', 'get /customer/auth/me', 'post /dashboard/auth/refresh', 'get /dashboard/auth/me'] as $key) {
        expect($ops[$key]['responses'])->toHaveKeys(['401', '403']);
    }
});

it('never exposes a credential field in the document', function () {
    $raw = generatedOpenApi()['__raw'];

    foreach (['password_hash', 'token_hash', 'otp_hash', 'mfa_secret', 'recovery_codes_hash'] as $forbidden) {
        expect($raw)->not->toContain($forbidden);
    }

    // The login/register request schemas legitimately carry a write-only `password`;
    // no response schema does.
    $schemas = generatedOpenApi()['components']['schemas'];
    foreach (['CustomerProfile', 'StaffProfile', 'Session'] as $name) {
        expect(array_keys($schemas[$name]['properties']))->not->toContain('password');
    }
});

it('stays in step with the registered routes', function () {
    $ops = documentedOperations(generatedOpenApi());

    $registered = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => preg_match('#^api/v1/(customer|dashboard|market|reference|withdrawal-confirmations|contact-changes)/#', $r->uri()))
        ->flatMap(fn ($r) => collect($r->methods())
            ->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS'], true))
            ->map(fn ($m) => strtolower($m).' /'.substr($r->uri(), strlen('api/v1/'))))
        ->all();

    expect(array_keys($ops))->toEqualCanonicalizing($registered);
});

// Spec 021: the held sign-in poll and the frozen founder's confirmation are public; the founder
// routes and the notices use the dashboard bearer, each under its own tag.
it('documents the founder security endpoints on their surfaces', function () {
    $ops = documentedOperations(generatedOpenApi());

    foreach (OPENAPI_FOUNDER_SECURITY_PUBLIC_PATHS as $key) {
        expect($ops[$key]['security'] ?? [])->toBe([], "{$key} is public")
            ->and($ops[$key]['tags'])->toBe(['Dashboard Auth']);
    }
    foreach (OPENAPI_FOUNDER_SECURITY_PATHS as $key) {
        expect($ops[$key]['security'])->toBe([['dashboardBearer' => []]])
            ->and($ops[$key]['tags'])->toBe(['Dashboard Founder Security']);
    }
    foreach (OPENAPI_NOTICE_PATHS as $key) {
        expect($ops[$key]['security'])->toBe([['dashboardBearer' => []]])
            ->and($ops[$key]['tags'])->toBe(['Dashboard Notices']);
    }

    // The held answer is a keyed variant of sign-in, MFA verify and MFA enroll.
    $doc = generatedOpenApi();
    expect($doc['components']['schemas'])->toHaveKey('StaffDeviceApprovalPending');
    foreach (['/dashboard/auth/login', '/dashboard/auth/mfa/verify', '/dashboard/auth/mfa/enroll'] as $path) {
        expect(json_encode($doc['paths'][$path]['post']['responses']['200']))->toContain('StaffDeviceApprovalPending');
    }
});
