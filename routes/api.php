<?php

use App\Http\Controllers\Api\V1\Customer\Auth\CustomerAuthController;
use App\Http\Controllers\Api\V1\Customer\Auth\CustomerRegistrationController;
use App\Http\Controllers\Api\V1\Customer\IdentityDocumentController as CustomerIdentityDocumentController;
use App\Http\Controllers\Api\V1\Customer\UploadController;
use App\Http\Controllers\Api\V1\Dashboard\Auth\StaffAuthController;
use App\Http\Controllers\Api\V1\Dashboard\Auth\StaffMfaController;
use App\Http\Controllers\Api\V1\Dashboard\CustomerController as DashboardCustomerController;
use App\Http\Controllers\Api\V1\Dashboard\IdentityDocumentController as DashboardIdentityDocumentController;
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

    // ─── Customer surface ────────────────────────────────────────────────
    Route::prefix('customer')->name('customer.')->group(function () {
        Route::prefix('auth')->name('auth.')->group(function () {
            // Six-step registration (docs Part 2 §§1–6). Only `submit` writes to
            // the database; steps 1–5 hold state in an encrypted cache entry
            // keyed by an opaque `registration_ref`.
            Route::prefix('register')->name('register.')->group(function () {
                Route::post('/start', [CustomerRegistrationController::class, 'start'])
                    ->middleware('throttle:auth.customer.register')->name('start');

                Route::post('/verify-phone-otp', [CustomerRegistrationController::class, 'verifyPhoneOtp'])
                    ->middleware('throttle:auth.customer.register.otp')->name('verify-phone-otp');

                Route::post('/email', [CustomerRegistrationController::class, 'email'])
                    ->middleware('throttle:auth.customer.register')->name('email');

                Route::post('/verify-email-otp', [CustomerRegistrationController::class, 'verifyEmailOtp'])
                    ->middleware('throttle:auth.customer.register.otp')->name('verify-email-otp');

                Route::post('/documents', [CustomerRegistrationController::class, 'documents'])
                    ->middleware('throttle:auth.customer.register')->name('documents');

                Route::post('/submit', [CustomerRegistrationController::class, 'submit'])
                    ->middleware('throttle:auth.customer.register')->name('submit');

                // Deprecated: use `submit`. Retained as a 410 stub for any
                // client still calling the previous name — throws
                // registration_endpoint_deprecated; writes nothing.
                Route::post('/complete', [CustomerRegistrationController::class, 'complete'])
                    ->middleware('throttle:auth.customer.register')->name('complete');
            });

            Route::post('/login', [CustomerAuthController::class, 'login'])
                ->middleware('throttle:auth.customer.login')->name('login');

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
        });
    });

    // ─── Dashboard/Staff surface ─────────────────────────────────────────
    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::prefix('auth')->name('auth.')->group(function () {
            Route::post('/login', [StaffAuthController::class, 'login'])
                ->middleware('throttle:auth.staff.login')->name('login');

            Route::middleware('throttle:auth.staff.mfa')->prefix('mfa')->name('mfa.')->group(function () {
                Route::post('/verify', [StaffMfaController::class, 'verify'])->name('verify');
                Route::post('/enroll', [StaffMfaController::class, 'enroll'])->name('enroll');
            });

            Route::middleware('auth:staff')->group(function () {
                Route::post('/refresh', [StaffAuthController::class, 'refresh'])
                    ->middleware(['abilities:staff:refresh', 'throttle:auth.refresh'])
                    ->name('refresh');

                Route::middleware('abilities:staff:access')->group(function () {
                    Route::get('/me', [StaffAuthController::class, 'me'])->name('me');
                    Route::post('/logout', [StaffAuthController::class, 'logout'])->name('logout');
                    Route::post('/logout-all', [StaffAuthController::class, 'logoutAll'])->name('logout-all');
                });
            });
        });

        // Dashboard "Users and Verification" page.
        Route::middleware(['auth:staff', 'abilities:staff:access'])
            ->prefix('customers')->name('customers.')->group(function () {
                Route::get('/', [DashboardCustomerController::class, 'index'])
                    ->middleware('staff.permission:customer.view')->name('index');

                Route::get('/{customer}', [DashboardCustomerController::class, 'show'])
                    ->whereUuid('customer')
                    ->middleware('staff.permission:customer.view')->name('show');
            });

        // Identity review.
        Route::middleware(['auth:staff', 'abilities:staff:access'])
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
    });
});
