<?php

use App\Enums\StaffPermission;
use App\Http\Controllers\Api\V1\Customer\AccountController as CustomerAccountController;
use App\Http\Controllers\Api\V1\Customer\Auth\CustomerAuthController;
use App\Http\Controllers\Api\V1\Customer\Auth\CustomerLoginOtpController;
use App\Http\Controllers\Api\V1\Customer\Auth\CustomerRegistrationController;
use App\Http\Controllers\Api\V1\Customer\BuyRequestController as CustomerBuyRequestController;
use App\Http\Controllers\Api\V1\Customer\CloseAccountController as CustomerCloseAccountController;
use App\Http\Controllers\Api\V1\Customer\IdentityDocumentController as CustomerIdentityDocumentController;
use App\Http\Controllers\Api\V1\Customer\InvoiceController as CustomerInvoiceController;
use App\Http\Controllers\Api\V1\Customer\ListingController as CustomerListingController;
use App\Http\Controllers\Api\V1\Customer\ListingQueueController as CustomerListingQueueController;
use App\Http\Controllers\Api\V1\Customer\ListingReportController as CustomerListingReportController;
use App\Http\Controllers\Api\V1\Customer\NotificationController as CustomerNotificationController;
use App\Http\Controllers\Api\V1\Customer\OrderController as CustomerOrderController;
use App\Http\Controllers\Api\V1\Customer\PayoutAccountController as CustomerPayoutAccountController;
use App\Http\Controllers\Api\V1\Customer\SavedPieceController as CustomerSavedPieceController;
use App\Http\Controllers\Api\V1\Customer\TopUpController as CustomerTopUpController;
use App\Http\Controllers\Api\V1\Customer\UploadController;
use App\Http\Controllers\Api\V1\Customer\WalletController as CustomerWalletController;
use App\Http\Controllers\Api\V1\Customer\WithdrawalController as CustomerWithdrawalController;
use App\Http\Controllers\Api\V1\Dashboard\AuditLogController as DashboardAuditLogController;
use App\Http\Controllers\Api\V1\Dashboard\Auth\StaffAuthController;
use App\Http\Controllers\Api\V1\Dashboard\Auth\StaffMfaController;
use App\Http\Controllers\Api\V1\Dashboard\BankMovementController as DashboardBankMovementController;
use App\Http\Controllers\Api\V1\Dashboard\BranchClosureController as DashboardBranchClosureController;
use App\Http\Controllers\Api\V1\Dashboard\BranchController as DashboardBranchController;
use App\Http\Controllers\Api\V1\Dashboard\BuyRequestController as DashboardBuyRequestController;
use App\Http\Controllers\Api\V1\Dashboard\CompensationController as DashboardCompensationController;
use App\Http\Controllers\Api\V1\Dashboard\CreditNoteController as DashboardCreditNoteController;
use App\Http\Controllers\Api\V1\Dashboard\CustomerController as DashboardCustomerController;
use App\Http\Controllers\Api\V1\Dashboard\DailyCloseController as DashboardDailyCloseController;
use App\Http\Controllers\Api\V1\Dashboard\DisputeController as DashboardDisputeController;
use App\Http\Controllers\Api\V1\Dashboard\ExtensionRequestController as DashboardExtensionRequestController;
use App\Http\Controllers\Api\V1\Dashboard\GoldPriceController as DashboardGoldPriceController;
use App\Http\Controllers\Api\V1\Dashboard\IdentityDocumentController as DashboardIdentityDocumentController;
use App\Http\Controllers\Api\V1\Dashboard\InspectionController as DashboardInspectionController;
use App\Http\Controllers\Api\V1\Dashboard\InvoiceController as DashboardInvoiceController;
use App\Http\Controllers\Api\V1\Dashboard\KaratAdjustmentController as DashboardKaratAdjustmentController;
use App\Http\Controllers\Api\V1\Dashboard\KaratController as DashboardKaratController;
use App\Http\Controllers\Api\V1\Dashboard\ListingController as DashboardListingController;
use App\Http\Controllers\Api\V1\Dashboard\ListingReportController as DashboardListingReportController;
use App\Http\Controllers\Api\V1\Dashboard\OrderController as DashboardOrderController;
use App\Http\Controllers\Api\V1\Dashboard\OverviewController as DashboardOverviewController;
use App\Http\Controllers\Api\V1\Dashboard\PayoutAccountController as DashboardPayoutAccountController;
use App\Http\Controllers\Api\V1\Dashboard\PermissionController as DashboardPermissionController;
use App\Http\Controllers\Api\V1\Dashboard\ReceivingAccountController as DashboardReceivingAccountController;
use App\Http\Controllers\Api\V1\Dashboard\RoleController as DashboardRoleController;
use App\Http\Controllers\Api\V1\Dashboard\SettingController as DashboardSettingController;
use App\Http\Controllers\Api\V1\Dashboard\StaffController as DashboardStaffController;
use App\Http\Controllers\Api\V1\Dashboard\StaffUploadController as DashboardStaffUploadController;
use App\Http\Controllers\Api\V1\Dashboard\TopUpController as DashboardTopUpController;
use App\Http\Controllers\Api\V1\Dashboard\WalletAdjustmentController as DashboardWalletAdjustmentController;
use App\Http\Controllers\Api\V1\Dashboard\WalletController as DashboardWalletController;
use App\Http\Controllers\Api\V1\Dashboard\WithdrawalController as DashboardWithdrawalController;
use App\Http\Controllers\Api\V1\Market\MarketListingController;
use App\Http\Controllers\Api\V1\Market\ReferenceController;
use App\Http\Controllers\Api\V1\Public\EmailChangeController;
use App\Http\Controllers\Api\V1\Public\WithdrawalConfirmationController;
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
            // Spec 017: the list the app shows, and how to reach Dahab.
            Route::get('/legal-documents', [ReferenceController::class, 'legalDocuments'])->name('legal-documents.index');
            Route::get('/support-contacts', [ReferenceController::class, 'supportContacts'])->name('support-contacts');
            Route::get('/legal-documents/{code}', [ReferenceController::class, 'legalDocument'])
                ->where('code', '[a-z][a-z0-9_]{1,49}')->name('legal-documents.show');
            // Spec 015: today's prices and the seller's estimate (indicative; nothing is locked).
            Route::get('/gold-prices', [ReferenceController::class, 'goldPrices'])->name('gold-prices');
            Route::get('/quote', [ReferenceController::class, 'quote'])->name('quote');
        });

        Route::middleware('db.market')->prefix('market/listings')->name('market.listings.')->group(function () {
            Route::get('/', [MarketListingController::class, 'index'])->name('index');
            Route::get('/{listing}', [MarketListingController::class, 'show'])->whereUuid('listing')->name('show');
            Route::get('/{listing}/media/{media}', [MarketListingController::class, 'media'])
                ->whereUuid(['listing', 'media'])->name('media');
        });
    });

    // ─── Customer surface ────────────────────────────────────────────────
    // Spec 013: the withdrawal email link's page. No token: the link's secret is the
    // key; read has no side effect, confirm needs the customer's tap (research R5).
    Route::middleware(['throttle:public.withdrawal_confirmations', 'db.elevate:bootstrap'])
        ->prefix('withdrawal-confirmations')->name('withdrawal-confirmations.')->group(function () {
            Route::post('/read', [WithdrawalConfirmationController::class, 'read'])->name('read');
            Route::post('/confirm', [WithdrawalConfirmationController::class, 'confirm'])->name('confirm');
        });

    // Spec 017: the email-change link's page, as the withdrawal link (research R2).
    Route::middleware(['throttle:public.email_change', 'db.elevate:bootstrap'])
        ->prefix('contact-changes/email')->name('contact-changes.email.')->group(function () {
            Route::post('/read', [EmailChangeController::class, 'read'])->name('read');
            Route::post('/confirm', [EmailChangeController::class, 'confirm'])->name('confirm');
        });

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

            // Spec 017: the customer's own account — every state may use it (Q7), so these
            // routes are open (CustomerRouteAccess). Each POST needs an Idempotency-Key.
            Route::post('/phone-change', [CustomerAccountController::class, 'requestPhoneChange'])
                ->middleware(['throttle:customer.contact_change', 'idempotent'])->name('phone-change.request');
            Route::post('/phone-change/{challenge}/confirm', [CustomerAccountController::class, 'confirmPhoneChange'])
                ->whereUuid('challenge')->middleware(['throttle:customer.contact_confirm', 'idempotent'])->name('phone-change.confirm');
            Route::post('/email-change', [CustomerAccountController::class, 'requestEmailChange'])
                ->middleware(['throttle:customer.contact_change', 'idempotent'])->name('email-change.request');
            Route::post('/password', [CustomerAccountController::class, 'changePassword'])
                ->middleware(['throttle:customer.password_change', 'idempotent'])->name('password.change');
            Route::get('/sessions', [CustomerAccountController::class, 'sessions'])->name('sessions.index');
            Route::post('/sessions/{session}/sign-out', [CustomerAccountController::class, 'signOutSession'])
                ->whereUuid('session')->middleware(['throttle:customer.session_sign_out', 'idempotent'])->name('sessions.sign-out');

            // Spec 017: the inbox (third channel next to SMS and email).
            Route::prefix('notifications')->name('notifications.')->group(function () {
                Route::get('/', [CustomerNotificationController::class, 'index'])->name('index');
                Route::get('/unread-count', [CustomerNotificationController::class, 'unreadCount'])->name('unread-count');
                Route::post('/read-all', [CustomerNotificationController::class, 'readAll'])->middleware('idempotent')->name('read-all');
                Route::post('/{notification}/read', [CustomerNotificationController::class, 'read'])
                    ->whereUuid('notification')->middleware('idempotent')->name('read');
            });

            // Spec 017: saved pieces (any signed-in customer, Q17).
            Route::prefix('saved-pieces')->name('saved-pieces.')->group(function () {
                Route::get('/', [CustomerSavedPieceController::class, 'index'])->name('index');
                Route::post('/', [CustomerSavedPieceController::class, 'store'])->middleware('idempotent')->name('store');
                Route::delete('/{listing}', [CustomerSavedPieceController::class, 'destroy'])->whereUuid('listing')->name('destroy');
            });

            // Spec 017: close my account (any state; refused while anything is open).
            Route::get('/account/close-check', [CustomerCloseAccountController::class, 'check'])->name('account.close-check');
            Route::post('/account/close', [CustomerCloseAccountController::class, 'close'])->middleware('idempotent')->name('account.close');

            // Spec 017: report a listing — verified and not suspended.
            Route::post('/listing-reports', [CustomerListingReportController::class, 'store'])
                ->middleware(['customer.gate:trade', 'throttle:customer.listing_reports', 'idempotent'])->name('listing-reports.store');

            // Spec 008: the customer's own wallet (verified; a suspended customer may read).
            Route::middleware('customer.gate:verified')->prefix('wallet')->name('wallet.')->group(function () {
                Route::get('/', [CustomerWalletController::class, 'show'])->name('show');
                Route::get('/transactions', [CustomerWalletController::class, 'transactions'])->name('transactions');
                // Spec 015: what each buy request and order holds now.
                Route::get('/held', [CustomerWalletController::class, 'held'])->name('held');

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
                    // Spec 011: the line on the seller's own listing.
                    Route::get('/{listing}/buy-requests', [CustomerListingQueueController::class, 'index'])
                        ->whereUuid('listing')->name('buy-requests');
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
                    // Spec 011: the seller answers the first buyer in line.
                    Route::post('/{listing}/accept', [CustomerListingQueueController::class, 'accept'])
                        ->whereUuid('listing')->middleware('idempotent')->name('accept');
                    Route::post('/{listing}/decline', [CustomerListingQueueController::class, 'decline'])
                        ->whereUuid('listing')->middleware('idempotent')->name('decline');
                });
            });

            // Spec 011: the buyer's requests. Sending needs the trade gate; reading and
            // leaving the queue need a verified customer (a suspended buyer may leave).
            // Every POST needs an Idempotency-Key (`idempotent` runs last).
            Route::prefix('buy-requests')->name('buy-requests.')->group(function () {
                Route::middleware('customer.gate:verified')->group(function () {
                    Route::get('/', [CustomerBuyRequestController::class, 'index'])->name('index');
                    Route::get('/{buyRequest}', [CustomerBuyRequestController::class, 'show'])->whereUuid('buyRequest')->name('show');
                    Route::post('/{buyRequest}/withdraw', [CustomerBuyRequestController::class, 'withdraw'])
                        ->whereUuid('buyRequest')->middleware('idempotent')->name('withdraw');
                });

                Route::middleware('customer.gate:trade')->group(function () {
                    Route::post('/', [CustomerBuyRequestController::class, 'store'])
                        ->middleware(['throttle:customer.buy_requests', 'idempotent'])->name('store');
                });
            });

            // Spec 012: the order after acceptance, buyer and seller. Reads, the seller's
            // cancel and the buyer's payment need a verified customer (a suspended
            // customer winds down open orders, Part 3 §9.2); relisting a returned
            // piece is new trading. Every POST needs an Idempotency-Key.
            Route::prefix('orders')->name('orders.')->group(function () {
                Route::middleware('customer.gate:verified')->group(function () {
                    Route::get('/', [CustomerOrderController::class, 'index'])->name('index');
                    Route::get('/{order}', [CustomerOrderController::class, 'show'])->whereUuid('order')->name('show');
                    Route::post('/{order}/cancel', [CustomerOrderController::class, 'cancel'])
                        ->whereUuid('order')->middleware('idempotent')->name('cancel');
                    Route::post('/{order}/decision', [CustomerOrderController::class, 'decision'])
                        ->whereUuid('order')->middleware('idempotent')->name('decision');
                    Route::post('/{order}/pay-balance', [CustomerOrderController::class, 'payBalance'])
                        ->whereUuid('order')->middleware('idempotent')->name('pay-balance');
                    // Spec 014: report a problem (a suspended customer may), ask for more time,
                    // withdraw the person named to collect.
                    Route::post('/{order}/disputes', [CustomerOrderController::class, 'openDispute'])
                        ->whereUuid('order')->middleware(['throttle:customer.disputes', 'idempotent'])->name('disputes.store');
                    Route::post('/{order}/extension-requests', [CustomerOrderController::class, 'requestMoreTime'])
                        ->whereUuid('order')->middleware('idempotent')->name('extension-requests.store');
                    Route::post('/{order}/proxy/remove', [CustomerOrderController::class, 'removeProxy'])
                        ->whereUuid('order')->middleware('idempotent')->name('proxy.remove');
                });

                Route::middleware('customer.gate:trade')->group(function () {
                    Route::post('/{order}/relist', [CustomerOrderController::class, 'relist'])
                        ->whereUuid('order')->middleware('idempotent')->name('relist');
                    // Spec 014: naming someone else to collect completes a trade (trade gate).
                    Route::post('/{order}/proxy', [CustomerOrderController::class, 'nameProxy'])
                        ->whereUuid('order')->middleware('idempotent')->name('proxy.store');
                });
            });

            // Spec 013: payout accounts. A suspended customer may read; every change
            // needs the trade gate and an Idempotency-Key (`idempotent` runs last).
            Route::prefix('payout-accounts')->name('payout-accounts.')->group(function () {
                Route::middleware('customer.gate:verified')->get('/', [CustomerPayoutAccountController::class, 'index'])->name('index');
                Route::middleware('customer.gate:trade')->group(function () {
                    Route::post('/', [CustomerPayoutAccountController::class, 'store'])
                        ->middleware(['throttle:customer.payout_accounts', 'idempotent'])->name('store');
                    foreach (['use', 'remove', 'keep'] as $action) {
                        Route::post("/{account}/{$action}", [CustomerPayoutAccountController::class, $action])
                            ->whereUuid('account')->middleware('idempotent')->name($action);
                    }
                });
            });

            // Spec 013: withdrawals. Verified gate throughout: a suspended customer may
            // still withdraw a remaining balance and cancel (Part 1 §2.2).
            Route::middleware('customer.gate:verified')->prefix('withdrawals')->name('withdrawals.')->group(function () {
                Route::get('/', [CustomerWithdrawalController::class, 'index'])->name('index');
                Route::post('/', [CustomerWithdrawalController::class, 'store'])
                    ->middleware(['throttle:customer.withdrawals', 'idempotent'])->name('store');
                Route::post('/confirmations', [CustomerWithdrawalController::class, 'requestConfirmation'])
                    ->middleware(['throttle:customer.withdrawals', 'idempotent'])->name('confirmations.store');
                Route::get('/confirmations/{confirmation}', [CustomerWithdrawalController::class, 'showConfirmation'])
                    ->whereUuid('confirmation')->name('confirmations.show');
                Route::get('/{withdrawal}', [CustomerWithdrawalController::class, 'show'])->whereUuid('withdrawal')->name('show');
                Route::post('/{withdrawal}/cancel', [CustomerWithdrawalController::class, 'cancel'])
                    ->whereUuid('withdrawal')->middleware('idempotent')->name('cancel');
            });

            // Spec 016: the customer's own tax invoices and credit notes (verified;
            // a suspended customer may read). Not audited.
            Route::middleware('customer.gate:verified')->group(function () {
                Route::prefix('invoices')->name('invoices.')->group(function () {
                    Route::get('/', [CustomerInvoiceController::class, 'index'])->name('index');
                    Route::get('/{invoice}', [CustomerInvoiceController::class, 'show'])->whereUuid('invoice')->name('show');
                    Route::get('/{invoice}/pdf', [CustomerInvoiceController::class, 'pdf'])->whereUuid('invoice')->name('pdf');
                });
                Route::get('/credit-notes/{creditNote}/pdf', [CustomerInvoiceController::class, 'creditNotePdf'])
                    ->whereUuid('creditNote')->name('credit-notes.pdf');
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
                // Spec 017: what the customer was sent (their inbox), read-only.
                Route::get('/{customer}/notifications', [DashboardCustomerController::class, 'notifications'])
                    ->whereUuid('customer')->middleware('staff.permission:customer.view')->name('notifications');
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

        // Spec 011: cancel an acceptance. Spec 012: the order's life after acceptance —
        // each action behind its own permission; the branch actions are scoped to the
        // staff member's assigned branch in the Action. Every POST is idempotent
        // (`idempotent` runs last) and audited.
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing'])
            ->prefix('orders')->name('orders.')->group(function () {
                Route::get('/', [DashboardOrderController::class, 'index'])
                    ->middleware('staff.permission:order.view')->name('index');
                // Spec 015 FR-018: the list as CSV, same filters and branch scope, audited.
                Route::get('/export', [DashboardOrderController::class, 'export'])
                    ->middleware('staff.permission:order.view')->name('export');
                Route::get('/{order}', [DashboardOrderController::class, 'show'])
                    ->whereUuid('order')->middleware('staff.permission:order.view')->name('show');
                Route::post('/{order}/cancel', [DashboardOrderController::class, 'cancel'])
                    ->whereUuid('order')->middleware(['staff.permission:order.cancel', 'idempotent'])->name('cancel');
                Route::post('/{order}/receive', [DashboardOrderController::class, 'receive'])
                    ->whereUuid('order')->middleware(['staff.permission:order.receive', 'idempotent'])->name('receive');
                Route::post('/{order}/inspection-results', [DashboardOrderController::class, 'inspectionResult'])
                    ->whereUuid('order')->middleware(['staff.permission:inspection.enter', 'idempotent'])->name('inspection-results');
                Route::post('/{order}/propose-price', [DashboardOrderController::class, 'proposePrice'])
                    ->whereUuid('order')->middleware(['staff.permission:order.price_adjust', 'idempotent'])->name('propose-price');
                Route::post('/{order}/handover', [DashboardOrderController::class, 'handover'])
                    ->whereUuid('order')->middleware(['staff.permission:order.handover', 'idempotent'])->name('handover');
                Route::post('/{order}/change-branch', [DashboardOrderController::class, 'changeBranch'])
                    ->whereUuid('order')->middleware(['staff.permission:order.change_branch', 'idempotent'])->name('change-branch');
                Route::post('/{order}/extend-deadline', [DashboardOrderController::class, 'extendDeadline'])
                    ->whereUuid('order')->middleware(['staff.permission:order.extend_deadline', 'idempotent'])->name('extend-deadline');
                // Spec 014: the ID photo of the person named to collect.
                Route::get('/{order}/proxy-id', [DashboardOrderController::class, 'proxyId'])
                    ->whereUuid('order')->middleware('staff.permission:order.handover|order.view')->name('proxy-id');
                Route::post('/{order}/seller-return/handover', [DashboardOrderController::class, 'sellerReturnHandover'])
                    ->whereUuid('order')->middleware(['staff.permission:order.handover', 'idempotent'])->name('seller-return.handover');
            });

        // Spec 014: the Disputes page. Every route needs dispute.handle; the resolve Action
        // also checks order.refund / compensation.pay / customer.suspend for the fields that
        // need them. Every POST is idempotent and audited.
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing', 'staff.permission:dispute.handle'])
            ->group(function () {
                Route::get('/dispute-assignees', [DashboardDisputeController::class, 'assignees'])->name('dispute-assignees.index');
                Route::prefix('disputes')->name('disputes.')->group(function () {
                    Route::get('/', [DashboardDisputeController::class, 'index'])->name('index');
                    Route::get('/{dispute}', [DashboardDisputeController::class, 'show'])->whereUuid('dispute')->name('show');
                    Route::get('/{dispute}/photos/{photo}', [DashboardDisputeController::class, 'photo'])
                        ->whereUuid(['dispute', 'photo'])->name('photos.show');
                    Route::post('/{dispute}/pass-on', [DashboardDisputeController::class, 'passOn'])
                        ->whereUuid('dispute')->middleware('idempotent')->name('pass-on');
                    Route::post('/{dispute}/resolve', [DashboardDisputeController::class, 'resolve'])
                        ->whereUuid('dispute')->middleware('idempotent')->name('resolve');
                });
            });

        // Spec 017: the listing-reports half of Disputes and reports. Take-down also needs
        // listing.takedown (the spec 010 take-down runs inside).
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing', 'staff.permission:listing_report.handle'])
            ->prefix('listing-reports')->name('listing-reports.')->group(function () {
                Route::get('/', [DashboardListingReportController::class, 'index'])->name('index');
                Route::get('/{report}', [DashboardListingReportController::class, 'show'])->whereUuid('report')->name('show');
                Route::post('/{report}/dismiss', [DashboardListingReportController::class, 'dismiss'])
                    ->whereUuid('report')->middleware('idempotent')->name('dismiss');
                Route::post('/{report}/take-down', [DashboardListingReportController::class, 'takeDown'])
                    ->whereUuid('report')->middleware(['staff.permission:listing.takedown', 'idempotent'])->name('take-down');
            });

        // Spec 014: sellers' requests for more time, answered in Orders.
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing'])
            ->prefix('extension-requests')->name('extension-requests.')->group(function () {
                Route::get('/', [DashboardExtensionRequestController::class, 'index'])
                    ->middleware('staff.permission:order.extend_deadline|order.view')->name('index');
                Route::post('/{extensionRequest}/accept', [DashboardExtensionRequestController::class, 'accept'])
                    ->whereUuid('extensionRequest')->middleware(['staff.permission:order.extend_deadline', 'idempotent'])->name('accept');
                Route::post('/{extensionRequest}/refuse', [DashboardExtensionRequestController::class, 'refuse'])
                    ->whereUuid('extensionRequest')->middleware(['staff.permission:order.extend_deadline', 'idempotent'])->name('refuse');
            });

        // Spec 012: the branch work list and the inspection results — never a price or a name.
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing'])
            ->prefix('inspections')->name('inspections.')->group(function () {
                Route::get('/', [DashboardInspectionController::class, 'index'])
                    ->middleware('staff.permission:'.StaffPermission::INSPECTIONS_ANY)->name('index');
                Route::get('/work-list', [DashboardInspectionController::class, 'workList'])
                    ->middleware('staff.permission:'.StaffPermission::WORK_LIST_ANY)->name('work-list');
            });

        // Spec 013: the Withdrawals page. Reads open with withdrawal.release or wallet.view
        // (read only); every action and the export need withdrawal.release.
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing'])
            ->prefix('withdrawals')->name('withdrawals.')->group(function () {
                Route::get('/', [DashboardWithdrawalController::class, 'index'])
                    ->middleware('staff.permission:'.StaffPermission::WITHDRAWALS_READ)->name('index');
                Route::get('/export', [DashboardWithdrawalController::class, 'export'])
                    ->middleware('staff.permission:withdrawal.release')->name('export');
                Route::get('/{withdrawal}', [DashboardWithdrawalController::class, 'show'])
                    ->whereUuid('withdrawal')->middleware('staff.permission:'.StaffPermission::WITHDRAWALS_READ)->name('show');
                foreach (['review', 'hold', 'unhold', 'release', 'reject'] as $action) {
                    Route::post("/{withdrawal}/{$action}", [DashboardWithdrawalController::class, $action])
                        ->whereUuid('withdrawal')->middleware(['staff.permission:withdrawal.release', 'idempotent'])->name($action);
                }
            });

        // Spec 015: finance operations. Reads open with the acting code or wallet.view;
        // every POST is idempotent (`idempotent` runs last) and audited.
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing'])->group(function () {
            Route::get('/overview', [DashboardOverviewController::class, 'show'])->name('overview');

            Route::prefix('compensation')->name('compensation.')->group(function () {
                Route::get('/', [DashboardCompensationController::class, 'index'])
                    ->middleware('staff.permission:'.StaffPermission::COMPENSATION_READ)->name('index');
                Route::get('/export', [DashboardCompensationController::class, 'export'])
                    ->middleware('staff.permission:'.StaffPermission::COMPENSATION_READ)->name('export');
                Route::post('/', [DashboardCompensationController::class, 'store'])
                    ->middleware(['staff.permission:compensation.pay', 'idempotent'])->name('store');
            });

            Route::post('/customers/{customer}/wallet-adjustments', [DashboardWalletAdjustmentController::class, 'store'])
                ->whereUuid('customer')->middleware(['staff.permission:wallet.adjust', 'idempotent'])->name('wallet-adjustments.store');
            Route::get('/wallet-adjustments', [DashboardWalletAdjustmentController::class, 'index'])
                ->middleware('staff.permission:'.StaffPermission::ADJUSTMENTS_READ)->name('wallet-adjustments.index');

            Route::post('/uploads', [DashboardStaffUploadController::class, 'store'])
                ->middleware(['staff.permission:bank.record', 'throttle:dashboard.uploads'])->name('uploads.store');
            Route::prefix('bank-movements')->name('bank-movements.')->group(function () {
                Route::get('/', [DashboardBankMovementController::class, 'index'])
                    ->middleware('staff.permission:'.StaffPermission::BANK_READ)->name('index');
                Route::get('/export', [DashboardBankMovementController::class, 'export'])
                    ->middleware('staff.permission:'.StaffPermission::BANK_READ)->name('export');
                Route::get('/{movement}/proof', [DashboardBankMovementController::class, 'proof'])
                    ->whereUuid('movement')->middleware('staff.permission:'.StaffPermission::BANK_READ)->name('proof');
                Route::post('/', [DashboardBankMovementController::class, 'store'])
                    ->middleware(['staff.permission:bank.record', 'idempotent'])->name('store');
            });
            Route::get('/bank-book', [DashboardBankMovementController::class, 'book'])
                ->middleware('staff.permission:'.StaffPermission::BANK_READ)->name('bank-book.index');
            Route::get('/bank-book/export', [DashboardBankMovementController::class, 'bookExport'])
                ->middleware('staff.permission:'.StaffPermission::BANK_READ)->name('bank-book.export');

            Route::get('/daily-close', [DashboardDailyCloseController::class, 'show'])
                ->middleware('staff.permission:'.StaffPermission::CLOSE_READ)->name('daily-close.show');
            Route::get('/daily-closes', [DashboardDailyCloseController::class, 'index'])
                ->middleware('staff.permission:'.StaffPermission::CLOSE_READ)->name('daily-closes.index');
            Route::post('/daily-close', [DashboardDailyCloseController::class, 'store'])
                ->middleware(['staff.permission:day.close', 'idempotent'])->name('daily-close.store');
        });

        // Spec 013: payout accounts to check (payout_account.verify).
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing', 'staff.permission:payout_account.verify'])
            ->prefix('payout-accounts')->name('payout-accounts.')->group(function () {
                Route::get('/', [DashboardPayoutAccountController::class, 'index'])->name('index');
                Route::post('/{account}/verify', [DashboardPayoutAccountController::class, 'verify'])
                    ->whereUuid('account')->middleware('idempotent')->name('verify');
                Route::post('/{account}/refuse', [DashboardPayoutAccountController::class, 'refuse'])
                    ->whereUuid('account')->middleware('idempotent')->name('refuse');
            });

        // Spec 012: buy requests across listings, read-only (product-owner decision 2026-10-01).
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing', 'staff.permission:buy_request.view'])
            ->get('/buy-requests', [DashboardBuyRequestController::class, 'index'])->name('buy-requests.index');

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

        // Spec 016: tax invoices and credit notes (invoice.view reads; invoice.correct issues a credit note).
        Route::middleware(['auth:staff', 'abilities:staff:access', 'staff.standing', 'staff.permission:invoice.view'])->group(function () {
            Route::prefix('invoices')->name('invoices.')->group(function () {
                Route::get('/', [DashboardInvoiceController::class, 'index'])->name('index');
                Route::get('/export', [DashboardInvoiceController::class, 'export'])->name('export');
                Route::get('/{invoice}', [DashboardInvoiceController::class, 'show'])->whereUuid('invoice')->name('show');
                Route::get('/{invoice}/pdf', [DashboardInvoiceController::class, 'pdf'])->whereUuid('invoice')->name('pdf');
                Route::post('/{invoice}/credit-notes', [DashboardInvoiceController::class, 'storeCreditNote'])->whereUuid('invoice')
                    ->middleware(['staff.permission:invoice.correct', 'idempotent'])->name('credit-notes.store');
            });
            Route::prefix('credit-notes')->name('credit-notes.')->group(function () {
                Route::get('/', [DashboardCreditNoteController::class, 'index'])->name('index');
                Route::get('/{creditNote}/pdf', [DashboardCreditNoteController::class, 'pdf'])->whereUuid('creditNote')->name('pdf');
            });
        });
    });
});
