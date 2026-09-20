<?php

namespace App\Actions\Auth\Customer;

use App\Enums\AuthErrorCode;
use App\Enums\IdentityDocumentKind;
use App\Exceptions\AuthApiException;
use App\Exceptions\DomainApiException;
use App\Services\CustomerRegistrationSessionStore;
use App\Services\IdentityDocumentStorage;
use App\Support\RequestContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Step 5 of registration: encrypt and store the identity document images
 * against the pending registration session. No `identity_document` row is
 * created — the storage_refs are held on the session and the row is written
 * only when submit succeeds.
 *
 * Egyptian ID requires front + back; passport accepts front only. If a
 * previous upload attempt left refs on the session (partial upload, network
 * retry), the old objects are deleted so nothing orphans on the disk.
 */
final class UploadCustomerRegistrationDocumentAction
{
    public function __construct(
        private readonly CustomerRegistrationSessionStore $sessions,
        private readonly IdentityDocumentStorage $storage,
    ) {}

    /**
     * @return array{doc_kind: string, front_uploaded: bool, back_uploaded: bool, expires_at: Carbon}
     */
    public function execute(
        string $ref,
        IdentityDocumentKind $kind,
        UploadedFile $front,
        ?UploadedFile $back,
        RequestContext $ctx,
    ): array {
        if ($kind === IdentityDocumentKind::EGYPTIAN_ID && $back === null) {
            throw DomainApiException::unsupportedDocKind();
        }

        $lock = $this->sessions->lock($ref);

        if (! $lock->get()) {
            throw new AuthApiException(AuthErrorCode::TOO_MANY_REQUESTS, 429, 'Upload already in progress.');
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

            if (($session['phone_verified'] ?? false) !== true) {
                throw new AuthApiException(
                    AuthErrorCode::REGISTRATION_PHONE_UNVERIFIED,
                    409,
                    'Verify the phone number before uploading a document.',
                );
            }

            if (($session['email_verified'] ?? false) !== true) {
                throw new AuthApiException(
                    AuthErrorCode::REGISTRATION_EMAIL_UNVERIFIED,
                    409,
                    'Verify the email address before uploading a document.',
                );
            }

            // Clean up any partial previous upload on the same session, so
            // repeated attempts do not orphan objects on the private disk.
            $this->forgetIfPresent($session['identity_front_ref'] ?? null);
            $this->forgetIfPresent($session['identity_back_ref'] ?? null);

            $frontRef = $this->storage->storeForRegistration($ref, $front);
            $backRef = $back !== null
                ? $this->storage->storeForRegistration($ref, $back)
                : null;

            $session['identity_doc_kind'] = $kind->value;
            $session['identity_front_ref'] = $frontRef;
            $session['identity_back_ref'] = $backRef;

            $this->sessions->save($ref, $session);

            return [
                'doc_kind' => $kind->value,
                'front_uploaded' => true,
                'back_uploaded' => $backRef !== null,
                'expires_at' => Carbon::parse($session['expires_at']),
            ];
        } finally {
            $lock->release();
        }
    }

    private function forgetIfPresent(?string $ref): void
    {
        if ($ref !== null && $this->storage->exists($ref)) {
            $this->storage->delete($ref);
        }
    }
}
