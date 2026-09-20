<?php

namespace App\Actions\Auth\Customer;

use App\Actions\Auth\Shared\RecordAuditLogAction;
use App\Enums\AuditEvent;
use App\Enums\AuthErrorCode;
use App\Enums\CustomerStatus;
use App\Enums\IdentityDocumentKind;
use App\Enums\IdentityDocumentStatus;
use App\Exceptions\AuthApiException;
use App\Models\Customer;
use App\Models\CustomerPassword;
use App\Models\IdentityDocument;
use App\Notifications\CustomerRegistrationSubmittedNotification;
use App\Services\CustomerRegistrationSessionStore;
use App\Services\IdentityDocumentStorage;
use App\Support\RequestContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Step 6, the only step that writes: creates the customer as
 * `pending_verification` together with its password (from the hash held on
 * the session), its identity document in `pending`, and — so that the
 * approved customer can actually log in later without another OTP dance —
 * marks the submitting device as trusted. No access/refresh token pair is
 * issued: the customer must wait for staff approval before any session
 * exists.
 *
 * Ordering: lock ref → load → re-validate uniqueness/verified/document →
 * BEGIN → create customer + password + document + trusted device + audit →
 * COMMIT → consume ref → notify.
 *
 * The customer only becomes `active` when a reviewer calls the identity
 * review Action with a `verify` decision.
 */
final class SubmitCustomerRegistrationAction
{
    public function __construct(
        private readonly CustomerRegistrationSessionStore $sessions,
        private readonly IdentityDocumentStorage $storage,
        private readonly RecordAuditLogAction $audit,
    ) {}

    /**
     * @return array{customer: Customer, identity_document: IdentityDocument}
     */
    public function execute(string $ref, RequestContext $ctx): array
    {
        $lock = $this->sessions->lock($ref);

        if (! $lock->get()) {
            throw new AuthApiException(AuthErrorCode::TOO_MANY_REQUESTS, 429, 'Submission already in progress.');
        }

        try {
            $session = $this->sessions->find($ref);

            if ($session === null) {
                throw new AuthApiException(
                    AuthErrorCode::REGISTRATION_SESSION_INVALID,
                    410,
                    'This registration session is no longer valid. Start again.',
                );
            }

            if (($session['submitted_at'] ?? null) !== null) {
                throw new AuthApiException(
                    AuthErrorCode::REGISTRATION_ALREADY_SUBMITTED,
                    409,
                    'This registration has already been submitted.',
                );
            }

            if (($session['phone_verified'] ?? false) !== true) {
                throw new AuthApiException(AuthErrorCode::REGISTRATION_PHONE_UNVERIFIED, 409, 'Verify the phone number before submitting.');
            }

            if (($session['email_verified'] ?? false) !== true) {
                throw new AuthApiException(AuthErrorCode::REGISTRATION_EMAIL_UNVERIFIED, 409, 'Verify the email address before submitting.');
            }

            if (empty($session['identity_doc_kind']) || empty($session['identity_front_ref'])) {
                throw new AuthApiException(AuthErrorCode::REGISTRATION_DOCUMENT_MISSING, 409, 'Upload an identity document before submitting.');
            }

            $kind = IdentityDocumentKind::from($session['identity_doc_kind']);

            if ($kind === IdentityDocumentKind::EGYPTIAN_ID && empty($session['identity_back_ref'])) {
                throw new AuthApiException(AuthErrorCode::REGISTRATION_DOCUMENT_MISSING, 409, 'Both sides of the ID are required.');
            }

            if (! $this->storage->exists($session['identity_front_ref'])) {
                throw new AuthApiException(AuthErrorCode::REGISTRATION_DOCUMENT_MISSING, 409, 'The uploaded document is no longer available.');
            }

            if ($session['identity_back_ref'] !== null && ! $this->storage->exists($session['identity_back_ref'])) {
                throw new AuthApiException(AuthErrorCode::REGISTRATION_DOCUMENT_MISSING, 409, 'The uploaded document is no longer available.');
            }

            $this->assertStillAvailable($session);

            $result = $this->create($session, $kind, $ctx);

            // Mark the session as consumed. The record is kept briefly so a
            // duplicate submit gives a specific error rather than a generic
            // "unknown session"; the whole entry lapses on the normal TTL.
            $session['submitted_at'] = now()->toIso8601String();
            $this->sessions->save($ref, $session);
            $this->sessions->forget($ref);

            $result['customer']->notify(new CustomerRegistrationSubmittedNotification);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array{customer: Customer, identity_document: IdentityDocument}
     */
    private function create(array $session, IdentityDocumentKind $kind, RequestContext $ctx): array
    {
        try {
            return DB::transaction(function () use ($session, $kind, $ctx) {
                $customer = Customer::query()->create([
                    'display_ref' => $this->newDisplayRef(),
                    'phone' => $session['phone'],
                    'email' => $session['email'],
                    'email_verified_at' => now(),
                    'full_name' => $session['full_name'],
                    'preferred_lang' => $session['preferred_lang'],
                    'governorate' => $session['governorate'],
                    'status' => CustomerStatus::PENDING_VERIFICATION->value,
                    'is_verified' => false,
                    'is_suspended' => false,
                ]);

                CustomerPassword::query()->create([
                    'customer_id' => $customer->customer_id,
                    // Already hashed at step 1; never re-hash a hash.
                    'password_hash' => $session['password_hash'],
                    'password_changed_at' => now(),
                ]);

                // No trusted device is created at submit time. The customer
                // must go through the normal customer authentication flow
                // (login → device trust) AFTER the reviewer has approved them.
                // Registration submission produces no session, no token pair
                // and no device trust — only the pending customer + pending
                // identity document rows.
                $fingerprint = $ctx->deviceFingerprintHash ?? $session['device_fingerprint_hash'] ?? null;

                $document = IdentityDocument::query()->create([
                    'customer_id' => $customer->customer_id,
                    'doc_kind' => $kind,
                    'front_ref' => $session['identity_front_ref'],
                    'back_ref' => $session['identity_back_ref'],
                    'status' => IdentityDocumentStatus::PENDING,
                ]);

                $actorCtx = RequestContext::forCustomer(request(), $customer->customer_id, $fingerprint);

                $this->audit->execute(
                    AuditEvent::CUSTOMER_REGISTRATION_SUBMITTED,
                    'success',
                    ['phone' => $customer->phone, 'doc_kind' => $kind->value],
                    'customer',
                    $customer->customer_id,
                    $actorCtx,
                );

                $this->audit->execute(
                    AuditEvent::IDENTITY_DOCUMENT_SUBMITTED,
                    'success',
                    ['doc_kind' => $kind->value, 'status' => IdentityDocumentStatus::PENDING->value],
                    'identity_document',
                    $document->document_id,
                    $actorCtx,
                    actorCustomerId: $customer->customer_id,
                );

                return ['customer' => $customer, 'identity_document' => $document];
            });
        } catch (UniqueConstraintViolationException) {
            // Last-line defence against two concurrent submits for the same
            // phone / email. Transaction rolled back, so no partial customer.
            throw ValidationException::withMessages([
                'phone' => ['This phone number is already registered.'],
            ]);
        }
    }

    /** @param array<string, mixed> $session */
    private function assertStillAvailable(array $session): void
    {
        $errors = [];

        if (Customer::query()->where('phone', $session['phone'])->exists()) {
            $errors['phone'] = ['This phone number is already registered.'];
        }

        if (! empty($session['email']) && Customer::query()->where('email', $session['email'])->exists()) {
            $errors['email'] = ['This email address is already registered.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function newDisplayRef(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $ref = str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            if (! Customer::query()->where('display_ref', $ref)->exists()) {
                return $ref;
            }
        }

        return substr(Str::uuid()->toString(), 0, 6);
    }
}
