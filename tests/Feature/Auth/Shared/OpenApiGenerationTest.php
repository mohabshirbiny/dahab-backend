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
];

const OPENAPI_DASHBOARD_PATHS = [
    'post /dashboard/auth/login',
    'post /dashboard/auth/mfa/verify',
    'post /dashboard/auth/mfa/enroll',
    'post /dashboard/auth/refresh',
    'get /dashboard/auth/me',
    'post /dashboard/auth/logout',
    'post /dashboard/auth/logout-all',
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
];

const OPENAPI_DASHBOARD_CUSTOMER_PATHS = [
    'get /dashboard/customers',
    'get /dashboard/customers/{customer}',
    // Spec 007: the Customer file.
    'post /dashboard/customers/{customer}/suspend',
    'post /dashboard/customers/{customer}/reinstate',
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
];

const OPENAPI_DASHBOARD_LISTING_PATHS = [
    'get /dashboard/listings',
    'get /dashboard/listings/{listing}',
    'get /dashboard/listings/{listing}/media/{media}',
    'post /dashboard/listings/{listing}/approve',
    'post /dashboard/listings/{listing}/request-changes',
    'post /dashboard/listings/{listing}/reject',
    'post /dashboard/listings/{listing}/takedown',
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

it('documents exactly the customer, dashboard and public endpoints', function () {
    $ops = documentedOperations(generatedOpenApi());

    expect(array_keys($ops))->toEqualCanonicalizing([
        ...OPENAPI_CUSTOMER_PATHS,
        ...OPENAPI_DASHBOARD_PATHS,
        ...OPENAPI_CUSTOMER_IDENTITY_PATHS,
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
        ...OPENAPI_MARKET_PATHS,
        ...OPENAPI_REFERENCE_PATHS,
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
        'get /dashboard/customers' => 'dashboardBearer',
        'get /dashboard/customers/{customer}' => 'dashboardBearer',
        'post /dashboard/customers/{customer}/suspend' => 'dashboardBearer',
        'post /dashboard/customers/{customer}/reinstate' => 'dashboardBearer',
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
        'ReviewIdentityDocumentRequest', 'StaffIdentityDocument', 'StaffCustomerVerification',
        'CustomerOtpChallenge', 'VerifyLoginOtpRequest', 'ResendLoginOtpRequest',
        'DashboardPermission', 'DashboardRole', 'DashboardRoleRef', 'DashboardStaffMember',
        'DashboardStoreRole', 'DashboardUpdateRole', 'DashboardDeleteRole', 'DashboardSetStaffRoles',
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
        'StoreListingRequest', 'UpdateListingRequest', 'RequestListingChangesRequest', 'ListingReasonRequest',
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
        ->filter(fn ($r) => preg_match('#^api/v1/(customer|dashboard|market|reference)/#', $r->uri()))
        ->flatMap(fn ($r) => collect($r->methods())
            ->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS'], true))
            ->map(fn ($m) => strtolower($m).' /'.substr($r->uri(), strlen('api/v1/'))))
        ->all();

    expect(array_keys($ops))->toEqualCanonicalizing($registered);
});
