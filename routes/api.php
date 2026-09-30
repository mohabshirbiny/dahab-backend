<?php

use App\Enums\StaffPermission;
use App\Http\Controllers\Api\V1\Customer\Auth\CustomerAuthController;
use App\Http\Controllers\Api\V1\Customer\Auth\CustomerLoginOtpController;
use App\Http\Controllers\Api\V1\Customer\Auth\CustomerRegistrationController;
use App\Http\Controllers\Api\V1\Customer\IdentityDocumentController as CustomerIdentityDocumentController;
use App\Http\Controllers\Api\V1\Customer\ListingController as CustomerListingController;
use App\Http\Controllers\Api\V1\Customer\TopUpController as CustomerTopUpController;
use App\Http\Controllers\Api\V1\Customer\UploadController;
use App\Http\Controllers\Api\V1\Customer\WalletController as CustomerWalletController;
use App\Http\Controllers\Api\V1\Dashboard\AuditLogController as DashboardAuditLogController;
use App\Http\Controllers\Api\V1\Dashboard\Auth\StaffAuthController;
use App\Http\Controllers\Api\V1\Dashboard\Auth\StaffMfaController;
use App\Http\Controllers\Api\V1\Dashboard\BranchClosureController as DashboardBranchClosureController;
use App\Http\Controllers\Api\V1\Dashboard\BranchController as DashboardBranchController;
use App\Http\Controllers\Api\V1\Dashboard\CustomerController as DashboardCustomerController;
use App\Http\Controllers\Api\V1\Dashboard\GoldPriceController as DashboardGoldPriceController;
use App\Http\Controllers\Api\V1\Dashboard\IdentityDocumentController as DashboardIdentityDocumentController;
use App\Http\Controllers\Api\V1\Dashboard\KaratAdjustmentController as DashboardKaratAdjustmentController;
use App\Http\Controllers\Api\V1\Dashboard\KaratController as DashboardKaratController;
use App\Http\Controllers\Api\V1\Dashboard\ListingController as DashboardListingController;
use App\Http\Controllers\Api\V1\Dashboard\PermissionController as DashboardPermissionController;
use App\Http\Controllers\Api\V1\Dashboard\ReceivingAccountController as DashboardReceivingAccountController;
use App\Http\Controllers\Api\V1\Dashboard\RoleController as DashboardRoleController;
use App\Http\Controllers\Api\V1\Dashboard\SettingController as DashboardSettingController;
use App\Http\Controllers\Api\V1\Dashboard\StaffController as DashboardStaffController;
use App\Http\Controllers\Api\V1\Dashboard\TopUpController as DashboardTopUpController;
use App\Http\Controllers\Api\V1\Dashboard\WalletController as DashboardWalletController;
use App\Http\Controllers\Api\V1\Market\MarketListingController;
use App\Http\Controllers\Api\V1\Market\ReferenceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => config('app.name'),
            'version' => 'v1',
            'time' => now()->toIso8601String(),
        ]);
    })->name('health');

    // ─── Public surface (spec 010) ───────────────────────────────────────
    // No account needed. Reference data for the apps, and the market: the
    // live pieces, read in the read-only `market` database scope (`db.market`)
    // and returned without any seller field (Part 1 §5.3). A customer token on
    // a market route is optional and only sets `is_mine`.
    Route::middleware('throttle:public.market')->group(function () {
        Route::prefix('reference')->name('reference.')->group(function () {
            Route::get('/karats', [ReferenceController::class, 'karats'])->name('karats');
            Route::get('/piece-types', [ReferenceController::class, 'pieceTypes'])->name('piece-types');
            Route::get('/branches', [ReferenceController::class, 'branches'])->name('branches');
            Route::get('/legal-documents/{code}', [ReferenceController::class, 'legalDocument'])
                ->where('code', '[a-z][a-z0-9_]{1,49}')->name('legal-documents.show');
        });

        Route::middleware('db.market')->prefix('market/listings')->name('market.listings.')->group(function () {
            Route::get('/', [MarketListingController::class, 'index'])->name('index');
            Route::get('/{listing}', [MarketListingController::class, 'show'])->whereUuid('listing')->name('show');
            Route::get('/{listing}/media/{media}', [MarketListingController::class, 'media'])
                ->whereUuid(['listing', 'media'])->name('media');
        });
    });

    // ─── Customer surface ────────────────────────────────────────────────
    Route::prefix('customer')->name('customer.')->group(function () {
        Route::prefix('auth')->name('auth.')->group(function () {
            // Six-step registration (docs Part 2 §§1–6). Only `submit` writes to
            // the database; steps 1–5 hold state in an encrypted cache entry
            // keyed by an opaque `registration_ref`.
            // No actor exists yet on these routes: they read/create customer rows
            // under an explicit row-level-security bootstrap elevation (spec 003).
            Route::middleware('db.elevate:bootstrap')->prefix('register')->name('register.')->group(function () {
                Route::post('/start', [CustomerRegistrationController::class, 'start'])
                    ->middleware('throttle:auth.customer.register')->name('start');

                Route::post('/verify-phone-otp', [CustomerRegistrationController::class, 'verifyPhoneOtp'])
                    ->middleware('throttle:auth.customer.register.otp')->name('verify-phone-otp');

                Route::post('/email', [CustomerRegistrationController::class, 'email'])
                    ->middleware('throttle:auth.customer.register')->name('email');

                Route::post('/verify-email-otp', [CustomerRegistrationController::class, 'verifyEmailOtp'])
                    ->middleware('throttle:auth.customer.register.otp')->name('verify-email-otp');

                Route::post('/documents', [CustomerRegistrationController::class, 'documents'])
                    ->middleware('throttle:auth.customer.register.documents')->name('documents');

                Route::post('/submit', [CustomerRegistrationController::class, 'submit'])
                    ->middleware('throttle:auth.customer.register')->name('submit');

                // Deprecated: use `submit`. Retained as a 410 stub for any
                // client still calling the previous name — throws
                // registration_endpoint_deprecated; writes nothing.
                Route::post('/complete', [CustomerRegistrationController::class, 'complete'])
                    ->middleware('throttle:auth.customer.register')->name('complete');
            });

            Route::post('/login', [CustomerAuthController::class, 'login'])
                ->middleware(['throttle:auth.customer.login', 'db.elevate:bootstrap'])->name('login');

            // New-device sign-in (Part 1 §2.3): login answers `otp_required`
            // with a challenge_id; these release it. Code guessing is capped
            // per challenge by the limiter and by the challenge itself.
            Route::middleware('db.elevate:bootstrap')->prefix('otp')->name('otp.')->group(function () {
                Route::post('/verify', [CustomerLoginOtpController::class, 'verify'])
                    ->middleware('throttle:auth.otp.verify')->name('verify');
                Route::post('/resend', [CustomerLoginOtpController::class, 'resend'])
                    ->name('resend');
            });

            Route::middleware('auth:customer')->group(function () {
                Route::post('/refresh', [CustomerAuthController::class, 'refresh'])
                    ->middleware(['abilities:customer:refresh', 'throttle:auth.refresh'])
                    ->name('refresh');

                Route::middleware('abilities:customer:access')->group(function () {
                    Route::get('/me', [CustomerAuthController::class, 'me'])->name('me');
                    Route::post('/logout', [CustomerAuthController::class, 'logout'])->name('logout');
                    Route::post('/logout-all', [CustomerAuthController::class, 'logoutAll'])->name('logout-all');
                });
            });
        });

        Route::middleware(['auth:customer', 'abilities:customer:access'])->prefix('me')->name('me.')->group(function () {
            Route::post('/uploads', [UploadController::class, 'store'])
                ->middleware('throttle:customer.uploads')->name('uploads.store');

            Route::post('/identity-documents', [CustomerIdentityDocumentController::class, 'store'])
                ->name('identity-documents.store');

            // Spec 008: the customer's own wallet (verified; a suspended customer may read).
            Route::middleware('customer.gate:verified')->prefix('wallet')->name('wallet.')->group(function () {
                Route::get('/', [CustomerWalletController::class, 'show'])->name('show');
                Route::get('/transactions', [CustomerWalletController::class, 'transactions'])->name('transactions');

                // Spec 009: a suspended customer may read and cancel their own notices.
                Route::get('/topups', [CustomerTopUpController::class, 'index'])->name('topups.index');
                Route::post('/topups/{topup}/cancel', [CustomerTopUpController::class, 'cancel'])
                    ->whereUuid('topup')->middleware('idempotent')->name('topups.cancel');
            });

            // Spec 009: receiving details and new notices need a verified, non-suspended
            // customer (trade gate, Part 1 §2.2). `idempotent` runs last.
            Route::middleware('customer.gate:trade')->prefix('wallet')->name('wallet.')->group(function () {
                Route::get('/topup-methods', [CustomerTopUpController::class, 'methods'])->name('topup-methods');
                Route::post('/topups', [CustomerTopUpController::class, 'store'])
                    ->middleware(['throttle:customer.topups', 'idempotent'])->name('topups.store');
            });

            // Spec 010: the seller's own listings. Reads need a verified customer (a
            // suspended seller may read); every write needs the trade gate and an
            // Idempotency-Key (`idempotent` runs last).
            Route::prefix('listings')->name('listings.')->group(function () {
                Route::middleware('customer.gate:verified')->group(function () {
                    Route::get('/', [CustomerListingController::class, 'index'])->name('index');
                    Route::get('/{listing}', [CustomerListingController::class, 'show'])->whereUuid('listing')->name('show');
                    Route::get('/{listing}/media/{media}', [CustomerListingController::class, 'media'])
                        ->whereUuid(['listing', 'media'])->name('media');
                });

                Route::middleware('customer.gate:trade')->group(function () {
                    Route::post('/', [CustomerListingController::class, 'store'])
                        ->middleware(['throttle:customer.listings', 'idempotent'])->name('store');
                    Route::patch('/{listing}', [CustomerListingController::class, 'update'])
                        ->whereUuid('listing')->middleware('idempotent')->name('update');
                    Route::post('/{listing}/submit', [CustomerListingController::class, 'submit'])
                        ->whereUuid('listing')->middleware('idempotent')->name('submit');
                    Route::post('/{listing}/withdraw', [CustomerListingController::class, 'withdraw'])
                        ->whereUuid('listing')->middleware('idempotent')->name('withdraw');
                });
            });
        });
    });

    // ─── Dashboard/Staff surface ─────────────────────────────────────────
    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::prefix('auth')->name('auth.')->group(function () {
            // No actor is bound yet, but sign-in writes staff-attributed audit rows
            // (RLS-protected): explicit bootstrap elevation, as for customer auth (spec 003).
            Route::post('/login', [StaffAuthController::class, 'login'])
                ->middleware(['throttle:auth.staff.login', 'db.elevate:bootstrap'])->name('login');

            Route::middleware(['throttle:auth.staff.mfa', 'db.elevate:bootstrap'])->prefix('mfa')->name('mfa.')->group(function () {
                Route::post('/verify', [StaffMfaController::class, 'verify'])->name('verify');
                Route::post('/enroll', [StaffMfaController::class, 'enroll'])->name('enroll');
            });

            Route::middleware('auth:staff')->group(function () {
                Route::post('/refresh', [StaffAuthController::class, 'refresh'])
                    ->middleware(['abilities:staff:refresh', 'staff.standing', 'throttle:auth.refresh'])
                    ->name('refresh');

                Route::middleware('abilities:staff:access')->group(function () {
                    Route::get('/me', [StaffAuthController::class, 'me'])->middleware('staff.standing')->name('me');
                    // No staff.standing: a frozen account must still be able to sign out.
                    Route::post('/logout', [StaffAuthController::class, 'logout'])->name('logout');
                    Route::post('/logout-all', [StaffAuthController::class, 'logoutAll'])->name('logout-all');
                });
            });
        });

        // Dashboard "Users and Verification" page.
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing'])
            ->prefix('customers')->name('customers.')->group(function () {
                Route::get('/', [DashboardCustomerController::class, 'index'])
                    ->middleware('staff.permission:customer.view')->name('index');

                Route::get('/{customer}', [DashboardCustomerController::class, 'show'])
                    ->whereUuid('customer')
                    ->middleware('staff.permission:customer.view')->name('show');

                // Customer file: suspend / reinstate (spec 007). `idempotent` runs last.
                Route::post('/{customer}/suspend', [DashboardCustomerController::class, 'suspend'])
                    ->whereUuid('customer')
                    ->middleware(['staff.permission:customer.suspend', 'idempotent'])->name('suspend');

                Route::post('/{customer}/reinstate', [DashboardCustomerController::class, 'reinstate'])
                    ->whereUuid('customer')
                    ->middleware(['staff.permission:customer.suspend', 'idempotent'])->name('reinstate');

                // Customer file: History (also needs an audit permission, checked in the Action) and sessions.
                Route::get('/{customer}/activity', [DashboardCustomerController::class, 'activity'])
                    ->whereUuid('customer')
                    ->middleware('staff.permission:customer.view')->name('activity');

                Route::get('/{customer}/sessions', [DashboardCustomerController::class, 'sessions'])
                    ->whereUuid('customer')
                    ->middleware('staff.permission:customer.view')->name('sessions');
            });

        // Identity review.
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing'])
            ->prefix('identity-documents')->name('identity-documents.')->group(function () {
                Route::get('/', [DashboardIdentityDocumentController::class, 'index'])
                    ->middleware('staff.permission:identity.view')->name('index');

                Route::get('/{document}', [DashboardIdentityDocumentController::class, 'show'])
                    ->whereUuid('document')
                    ->middleware('staff.permission:identity.view')->name('show');

                Route::get('/{document}/image', [DashboardIdentityDocumentController::class, 'image'])
                    ->whereUuid('document')
                    ->middleware('staff.permission:identity.view')->name('image');

                Route::post('/{document}/review', [DashboardIdentityDocumentController::class, 'review'])
                    ->whereUuid('document')
                    ->middleware('staff.permission:identity.review')->name('review');
            });

        // Access control: roles and their permissions (spec 002).
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing', 'staff.permission:roles.manage'])
            ->group(function () {
                Route::get('/permissions', [DashboardPermissionController::class, 'index'])->name('permissions.index');

                Route::prefix('roles')->name('roles.')->group(function () {
                    Route::get('/', [DashboardRoleController::class, 'index'])->name('index');
                    Route::post('/', [DashboardRoleController::class, 'store'])->name('store');
                    Route::get('/{role}', [DashboardRoleController::class, 'show'])->where('role', '[a-z][a-z0-9_]{2,49}')->name('show');
                    Route::patch('/{role}', [DashboardRoleController::class, 'update'])->where('role', '[a-z][a-z0-9_]{2,49}')->name('update');
                    Route::delete('/{role}', [DashboardRoleController::class, 'destroy'])->where('role', '[a-z][a-z0-9_]{2,49}')->name('destroy');
                });
            });

        // Access control: staff members and their roles (spec 002).
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing'])
            ->prefix('staff')->name('staff.')->group(function () {
                Route::get('/', [DashboardStaffController::class, 'index'])
                    ->middleware('staff.permission:staff.view')->name('index');

                Route::get('/{staff}', [DashboardStaffController::class, 'show'])
                    ->whereUuid('staff')
                    ->middleware('staff.permission:staff.view')->name('show');

                Route::put('/{staff}/roles', [DashboardStaffController::class, 'updateRoles'])
                    ->whereUuid('staff')
                    ->middleware('staff.permission:roles.manage')->name('roles.update');

                Route::put('/{staff}/branch', [DashboardStaffController::class, 'updateBranch'])
                    ->whereUuid('staff')
                    ->middleware('staff.permission:roles.manage')->name('branch.update');
            });

        // Pricing: gold prices, per-karat adjustments and settings (spec 005).
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing'])
            ->prefix('gold-prices')->name('gold-prices.')->group(function () {
                Route::get('/', [DashboardGoldPriceController::class, 'index'])
                    ->middleware('staff.permission:pricing.view')->name('index');
                Route::get('/current', [DashboardGoldPriceController::class, 'current'])
                    ->middleware('staff.permission:pricing.view')->name('current');
                Route::post('/preview', [DashboardGoldPriceController::class, 'preview'])
                    ->middleware('staff.permission:pricing.view')->name('preview');
                Route::post('/manual', [DashboardGoldPriceController::class, 'manual'])
                    ->middleware('staff.permission:gold_price.enter')->name('manual');
                Route::post('/manual/{manualPrice}/confirm', [DashboardGoldPriceController::class, 'confirm'])
                    ->whereNumber('manualPrice')
                    ->middleware('staff.permission:gold_price.confirm')->name('manual.confirm');
            });

        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing'])->group(function () {
            Route::prefix('settings')->name('settings.')->group(function () {
                Route::get('/', [DashboardSettingController::class, 'index'])
                    ->middleware('staff.permission:pricing.view')->name('index');
                Route::get('/history', [DashboardSettingController::class, 'history'])
                    ->middleware('staff.permission:pricing.view')->name('history');
                // The permission depends on the key's group: checked in the controller.
                Route::patch('/{key}', [DashboardSettingController::class, 'update'])
                    ->where('key', '[a-z_]+(\\.[a-z_]+)+')->name('update');
            });

            Route::put('/karats/{code}/adjustments', [DashboardKaratAdjustmentController::class, 'update'])
                ->whereNumber('code')
                ->middleware('staff.permission:pricing.rates.manage')->name('karats.adjustments.update');
            Route::get('/price-adjustments/history', [DashboardKaratAdjustmentController::class, 'history'])
                ->middleware('staff.permission:pricing.view')->name('price-adjustments.history');
        });

        // Wallets (spec 008): reads only; CEO and Finance by default, never the COO.
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing', 'staff.permission:wallet.view'])->group(function () {
            Route::get('/wallets/overview', [DashboardWalletController::class, 'overview'])->name('wallets.overview');
            Route::get('/customers/{customer}/wallet', [DashboardWalletController::class, 'customerWallet'])
                ->whereUuid('customer')->name('customers.wallet');
            Route::get('/wallet-statement', [DashboardWalletController::class, 'statement'])->name('wallet-statement.show');
            Route::get('/wallet-statement/export', [DashboardWalletController::class, 'export'])->name('wallet-statement.export');
        });

        // Incoming transfers (spec 009): CEO and Finance by default, never the COO.
        // Every POST is idempotent (`idempotent` runs last); match and credit by hand move money.
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing', 'staff.permission:topup.match'])
            ->prefix('topups')->name('topups.')->group(function () {
                Route::get('/', [DashboardTopUpController::class, 'index'])->name('index');
                Route::post('/', [DashboardTopUpController::class, 'store'])->middleware('idempotent')->name('store');
                Route::get('/export', [DashboardTopUpController::class, 'export'])->name('export');
                Route::get('/{topup}', [DashboardTopUpController::class, 'show'])->whereUuid('topup')->name('show');
                Route::get('/{topup}/receipt', [DashboardTopUpController::class, 'receipt'])->whereUuid('topup')->name('receipt');
                foreach (['match', 'hold', 'unhold', 'reject'] as $action) {
                    Route::post("/{topup}/{$action}", [DashboardTopUpController::class, $action])
                        ->whereUuid('topup')->middleware('idempotent')->name($action);
                }
            });

        // Dahab's receiving accounts (spec 009 US4): read with either top-up code, change with topup.accounts.manage.
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing'])
            ->prefix('receiving-accounts')->name('receiving-accounts.')->group(function () {
                Route::get('/', [DashboardReceivingAccountController::class, 'index'])
                    ->middleware('staff.permission:topup.match|topup.accounts.manage')->name('index');
                Route::post('/', [DashboardReceivingAccountController::class, 'store'])
                    ->middleware('staff.permission:topup.accounts.manage')->name('store');
                Route::patch('/{account}', [DashboardReceivingAccountController::class, 'update'])
                    ->whereNumber('account')->middleware('staff.permission:topup.accounts.manage')->name('update');
            });

        // Listings to review (spec 010): CEO, COO and Operations by default. Reading
        // needs any listing permission; each decision its own. Every POST is
        // idempotent (`idempotent` runs last) and audited.
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing'])
            ->prefix('listings')->name('listings.')->group(function () {
                Route::middleware('staff.permission:'.StaffPermission::LISTING_ANY)->group(function () {
                    Route::get('/', [DashboardListingController::class, 'index'])->name('index');
                    Route::get('/{listing}', [DashboardListingController::class, 'show'])->whereUuid('listing')->name('show');
                    Route::get('/{listing}/media/{media}', [DashboardListingController::class, 'media'])
                        ->whereUuid(['listing', 'media'])->name('media');
                });

                Route::post('/{listing}/approve', [DashboardListingController::class, 'approve'])
                    ->whereUuid('listing')->middleware(['staff.permission:listing.review', 'idempotent'])->name('approve');
                Route::post('/{listing}/reject', [DashboardListingController::class, 'reject'])
                    ->whereUuid('listing')->middleware(['staff.permission:listing.review', 'idempotent'])->name('reject');
                Route::post('/{listing}/request-changes', [DashboardListingController::class, 'requestChanges'])
                    ->whereUuid('listing')->middleware(['staff.permission:listing.request_changes', 'idempotent'])->name('request-changes');
                Route::post('/{listing}/takedown', [DashboardListingController::class, 'takedown'])
                    ->whereUuid('listing')->middleware(['staff.permission:listing.takedown', 'idempotent'])->name('takedown');
            });

        // The audit log viewer (spec 006): everything, or your own actions only.
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing', 'staff.permission:audit.view_all|audit.view_own'])
            ->prefix('audit-log')->name('audit-log.')->group(function () {
                Route::get('/', [DashboardAuditLogController::class, 'index'])->name('index');
                Route::get('/categories', [DashboardAuditLogController::class, 'categories'])->name('categories');
                Route::get('/export', [DashboardAuditLogController::class, 'export'])->name('export');
                Route::get('/{entry}', [DashboardAuditLogController::class, 'show'])->whereNumber('entry')->name('show');
            });

        // Reference data: karats, branches, weekly hours and closures (spec 004).
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing'])->group(function () {
            Route::prefix('karats')->name('karats.')->group(function () {
                Route::get('/', [DashboardKaratController::class, 'index'])
                    ->middleware('staff.permission:reference.view')->name('index');
                Route::post('/', [DashboardKaratController::class, 'store'])
                    ->middleware('staff.permission:karats.create')->name('store');
                Route::post('/{code}/toggle', [DashboardKaratController::class, 'toggle'])
                    ->whereNumber('code')
                    ->middleware('staff.permission:karats.toggle')->name('toggle');
            });

            Route::prefix('branches')->name('branches.')->group(function () {
                Route::get('/', [DashboardBranchController::class, 'index'])
                    ->middleware('staff.permission:reference.view')->name('index');
                Route::post('/', [DashboardBranchController::class, 'store'])
                    ->middleware('staff.permission:branches.manage')->name('store');
                Route::patch('/{branch}', [DashboardBranchController::class, 'update'])
                    ->whereNumber('branch')
                    ->middleware('staff.permission:branches.manage')->name('update');
            });

            Route::prefix('branch-closures')->name('branch-closures.')->group(function () {
                Route::get('/', [DashboardBranchClosureController::class, 'index'])
                    ->middleware('staff.permission:reference.view')->name('index');
                Route::post('/', [DashboardBranchClosureController::class, 'store'])
                    ->middleware('staff.permission:branches.manage')->name('store');
                Route::delete('/{closure}', [DashboardBranchClosureController::class, 'destroy'])
                    ->whereNumber('closure')
                    ->middleware('staff.permission:branches.manage')->name('destroy');
            });
        });
    });
});
