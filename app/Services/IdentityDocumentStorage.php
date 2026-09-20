<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The private object store for identity images. The disk is never public and
 * has no URL; bytes are encrypted application-side before they touch it
 * (docs/Database schema/02_schema_identity.sql: "photos are encrypted at
 * rest"), so a leaked bucket or backup holds only ciphertext. The returned
 * reference is what `identity_document.storage_ref` records.
 */
final class IdentityDocumentStorage
{
    public const DISK = 'identity_private';

    /** Encrypt and store an uploaded image for a customer; returns the object key. */
    public function store(string $customerId, UploadedFile $file): string
    {
        $ref = 'identity/'.$customerId.'/'.Str::uuid().'.enc';

        $this->putAt($ref, (string) $file->get());

        return $ref;
    }

    /**
     * Store a file for an in-flight registration (no customer row yet).
     * The object lives under `identity-pending/{registration_ref}/…`; on
     * submit its key is kept and simply recorded on the identity_document
     * row, so nothing has to be moved. Abandoned sessions are swept by the
     * scheduled cleanup command (see PruneAbandonedRegistrationDocuments).
     */
    public function storeForRegistration(string $registrationRef, UploadedFile $file): string
    {
        $ref = 'identity-pending/'.$registrationRef.'/'.Str::uuid().'.enc';

        $this->putAt($ref, (string) $file->get());

        return $ref;
    }

    /** All object keys stored under a registration prefix — used by the cleanup command. */
    public function listForRegistration(string $registrationRef): array
    {
        return Storage::disk(self::DISK)->allFiles('identity-pending/'.$registrationRef);
    }

    public function putAt(string $ref, string $bytes): void
    {
        // The disk is configured with `throw => true`: a failed write raises.
        Storage::disk(self::DISK)->put($ref, Crypt::encryptString($bytes));
    }

    /** Decrypted image bytes. Throws if the object is missing or cannot be decrypted. */
    public function read(string $ref): string
    {
        return Crypt::decryptString(Storage::disk(self::DISK)->get($ref));
    }

    public function exists(string $ref): bool
    {
        return Storage::disk(self::DISK)->exists($ref);
    }

    public function delete(string $ref): void
    {
        Storage::disk(self::DISK)->delete($ref);
    }
}
