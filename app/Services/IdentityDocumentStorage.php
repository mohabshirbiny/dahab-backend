<?php

namespace App\Services;

use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The private object store for customer uploads: identity images, since
 * spec 009 top-up receipts (images or PDF) and, since spec 010, listing media
 * (photos, video, invoice, stone certificate — stored in encrypted chunks,
 * see storeChunkedAt()). The disk is never public and
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
     * Encrypt and store an upload for a customer under another prefix (spec 009:
     * `topup-receipts`); returns the object key.
     */
    public function storeAt(string $prefix, string $customerId, UploadedFile $file): string
    {
        $ref = $prefix.'/'.$customerId.'/'.Str::uuid().'.enc';

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

    /** First bytes of a chunk-encrypted object. */
    public const CHUNKED_MAGIC = 'DHC1';

    /**
     * Encrypt and store an upload in chunks (spec 010 research R7): the file
     * is read a chunk at a time, each chunk is encrypted on its own and
     * appended as a length-prefixed frame, and the result is written to the
     * disk as a stream. Memory stays at a few chunks whatever the file size,
     * so a 50 MB video never sits whole in memory. Object layout:
     * `DHC1`, then per chunk a 4-byte big-endian length and that many bytes
     * of ciphertext. Read it back with readChunked().
     */
    public function storeChunkedAt(string $prefix, string $customerId, UploadedFile $file): string
    {
        $ref = $prefix.'/'.$customerId.'/'.Str::uuid().'.encs';
        $chunkBytes = max(1024, (int) config('dahab-listings.chunk_bytes'));

        $in = fopen($file->getRealPath(), 'rb');
        $out = tmpfile();

        if ($in === false || $out === false) {
            throw new RuntimeException('The upload could not be opened for encryption.');
        }

        try {
            fwrite($out, self::CHUNKED_MAGIC);

            while (! feof($in)) {
                $chunk = fread($in, $chunkBytes);

                if ($chunk === false || $chunk === '') {
                    continue;
                }

                $cipher = Crypt::encryptString($chunk);
                fwrite($out, pack('N', strlen($cipher)).$cipher);
            }

            rewind($out);
            // The disk is configured with `throw => true`: a failed write raises.
            Storage::disk(self::DISK)->writeStream($ref, $out);
        } finally {
            fclose($in);
            if (is_resource($out)) {
                fclose($out);
            }
        }

        return $ref;
    }

    /**
     * The decrypted chunks of an object written by storeChunkedAt(), one at a
     * time. Throws if the object is missing, truncated or tampered with.
     *
     * @return Generator<int, string>
     */
    public function readChunked(string $ref): Generator
    {
        $stream = Storage::disk(self::DISK)->readStream($ref);

        if (! is_resource($stream)) {
            throw new RuntimeException("The stored object [{$ref}] could not be opened.");
        }

        try {
            if (fread($stream, strlen(self::CHUNKED_MAGIC)) !== self::CHUNKED_MAGIC) {
                throw new RuntimeException("The stored object [{$ref}] is not a chunked object.");
            }

            while (true) {
                $header = $this->readExactly($stream, 4);

                if ($header === '') {
                    return;
                }

                $length = strlen($header) === 4 ? unpack('N', $header)[1] : 0;
                $cipher = $length > 0 ? $this->readExactly($stream, $length) : '';

                if ($length < 1 || strlen($cipher) !== $length) {
                    throw new RuntimeException("The stored object [{$ref}] is truncated.");
                }

                yield Crypt::decryptString($cipher);
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Up to `$length` bytes; fewer only at the end of the stream.
     *
     * @param  resource  $stream
     */
    private function readExactly($stream, int $length): string
    {
        $data = '';

        while (strlen($data) < $length && ! feof($stream)) {
            $part = fread($stream, $length - strlen($data));

            if ($part === false) {
                break;
            }

            $data .= $part;
        }

        return $data;
    }

    public function putAt(string $ref, string $bytes): void
    {
        // The disk is configured with `throw => true`: a failed write raises.
        Storage::disk(self::DISK)->put($ref, Crypt::encryptString($bytes));
    }

    /** Decrypted bytes. Throws if the object is missing or cannot be decrypted. */
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
