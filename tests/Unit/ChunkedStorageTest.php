<?php

use App\Services\IdentityDocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

// Spec 010 research R7 (analysis U1): listing media is encrypted and read
// back in chunks, so a large video is never held whole in memory.

beforeEach(function () {
    Storage::fake('identity_private');
    config(['dahab-listings.chunk_bytes' => 1024 * 1024]);
    $this->storage = app(IdentityDocumentStorage::class);
});

function chunkedFile(string $bytes): UploadedFile
{
    return UploadedFile::fake()->createWithContent('media.bin', $bytes);
}

it('round-trips files of any size', function (int $size) {
    $bytes = $size === 0 ? '' : random_bytes($size);

    $ref = $this->storage->storeChunkedAt('listing-media', 'customer-1', chunkedFile($bytes));
    $chunks = iterator_to_array($this->storage->readChunked($ref), false);

    expect(implode('', $chunks))->toBe($bytes)
        ->and(count($chunks))->toBe((int) ceil($size / (1024 * 1024)))
        ->and($ref)->toStartWith('listing-media/customer-1/')->toEndWith('.encs');
})->with([
    'empty' => [0],
    'one byte' => [1],
    'exactly one chunk' => [1024 * 1024],
    'two and a half chunks' => [(int) (2.5 * 1024 * 1024)],
]);

it('stores ciphertext only', function () {
    $bytes = str_repeat('DAHAB-PLAINTEXT-', 4096);
    $ref = $this->storage->storeChunkedAt('listing-media', 'customer-1', chunkedFile($bytes));
    $stored = (string) Storage::disk('identity_private')->get($ref);

    expect(substr($stored, 0, 4))->toBe('DHC1')
        ->and(str_contains($stored, 'DAHAB-PLAINTEXT-'))->toBeFalse();
});

it('refuses a truncated object', function () {
    $ref = $this->storage->storeChunkedAt('listing-media', 'customer-1', chunkedFile(random_bytes(3 * 1024 * 1024)));
    $stored = (string) Storage::disk('identity_private')->get($ref);
    Storage::disk('identity_private')->put($ref, substr($stored, 0, (int) (strlen($stored) / 2)));

    expect(fn () => iterator_to_array($this->storage->readChunked($ref), false))->toThrow(RuntimeException::class);
});

it('refuses a tampered object', function () {
    $ref = $this->storage->storeChunkedAt('listing-media', 'customer-1', chunkedFile(random_bytes(2048)));
    $stored = (string) Storage::disk('identity_private')->get($ref);
    $stored[40] = $stored[40] === 'A' ? 'B' : 'A';
    Storage::disk('identity_private')->put($ref, $stored);

    expect(fn () => iterator_to_array($this->storage->readChunked($ref), false))->toThrow(Exception::class);
});

it('refuses an object that is not chunked', function () {
    Storage::disk('identity_private')->put('listing-media/x/plain.enc', 'not a chunked object');

    expect(fn () => iterator_to_array($this->storage->readChunked('listing-media/x/plain.enc'), false))->toThrow(RuntimeException::class);
});
