<?php

use App\Enums\UploadPurpose;
use App\Models\Customer;
use App\Services\IdentityDocumentStorage;
use App\Services\UploadTokenStore;
use App\Support\SystemActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 FR-009, FR-012, research R7: listing media goes through
// POST /customer/me/uploads with four new purposes — trade-gated, checked by
// content, size-limited, and stored encrypted in chunks on the private disk.
// Files are real files on disk, so the type is sniffed as in production.

beforeEach(function () {
    Storage::fake('identity_private');
    $this->customer = Customer::factory()->verified()->create();
});

/** The storage key behind a token. */
function uploadedRef(string $token, Customer $customer, UploadPurpose $purpose): string
{
    return (string) app(UploadTokenStore::class)->resolve($token, $customer->customer_id, $purpose);
}

it('accepts each listing purpose with its own types', function (string $purpose, UploadedFile $file) {
    Listings::upload($this, $this->customer, $purpose, $file)
        ->assertCreated()
        ->assertJsonPath('data.purpose', $purpose)
        ->assertJsonStructure(['data' => ['upload_token', 'purpose', 'expires_in']]);
})->with([
    'a photo (png)' => ['listing_photo', fn () => Listings::png()],
    'a photo (jpeg)' => ['listing_photo', fn () => Listings::jpeg()],
    'a video (mp4)' => ['listing_video', fn () => Listings::mp4()],
    'an invoice (pdf)' => ['listing_invoice', fn () => Listings::pdf()],
    'an invoice (image)' => ['listing_invoice', fn () => Listings::png('invoice.png')],
    'a certificate (pdf)' => ['stone_certificate', fn () => Listings::pdf('cert.pdf')],
    'a certificate (image)' => ['stone_certificate', fn () => Listings::png('cert.png')],
]);

it('refuses a file of the wrong kind, by content not by name', function (string $purpose, UploadedFile $file) {
    Listings::upload($this, $this->customer, $purpose, $file)
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('file');

    expect(Storage::disk('identity_private')->allFiles())->toBe([]);
})->with([
    'a pdf as a photo' => ['listing_photo', fn () => Listings::pdf('photo.pdf')],
    'a pdf renamed .jpg as a photo' => ['listing_photo', fn () => Listings::file(Listings::pdfBytes(), 'photo.jpg')],
    'a program renamed .jpg' => ['listing_photo', fn () => Listings::file('MZ'.str_repeat(chr(144).chr(0).chr(3), 80), 'photo.jpg')],
    'a photo as a video' => ['listing_video', fn () => Listings::png('clip.mp4')],
    'a video as an invoice' => ['listing_invoice', fn () => Listings::mp4('invoice.pdf')],
    'a text file as a certificate' => ['stone_certificate', fn () => Listings::file('just some text', 'cert.pdf')],
]);

it('limits a photo and a document to 8 MB', function (string $purpose) {
    $big = Listings::png('big.png', 8193 * 1024);

    Listings::upload($this, $this->customer, $purpose, $big)->assertStatus(422)->assertJsonValidationErrors('file');
})->with(['listing_photo', 'listing_invoice', 'stone_certificate']);

it('lets a video be larger than 8 MB', function () {
    Listings::upload($this, $this->customer, 'listing_video', Listings::mp4('clip.mp4', 9 * 1024 * 1024))->assertCreated();
});

it('needs a verified, non-suspended customer for every listing purpose', function (string $purpose) {
    $pending = Customer::factory()->pendingVerification()->create();
    $suspended = Customer::factory()->suspended(byStaffId: SystemActor::id())->create();
    $file = fn () => $purpose === 'listing_video' ? Listings::mp4() : Listings::png();

    Listings::upload($this, $pending, $purpose, $file())->assertForbidden()->assertJsonPath('code', 'verification_required');
    Listings::upload($this, $suspended, $purpose, $file())->assertForbidden()->assertJsonPath('code', 'account_suspended');

    expect(Storage::disk('identity_private')->allFiles())->toBe([]);
})->with(['listing_photo', 'listing_video', 'listing_invoice', 'stone_certificate']);

it('keeps identity uploads open to a customer waiting for verification', function () {
    $pending = Customer::factory()->pendingVerification()->create();

    Listings::upload($this, $pending, 'identity')->assertCreated();
});

it('stores listing media as encrypted chunks under the customer', function () {
    $file = Listings::png();
    $plain = (string) file_get_contents($file->getRealPath());

    $token = Listings::uploadToken($this, $this->customer, 'listing_photo', $file);
    $ref = uploadedRef($token, $this->customer, UploadPurpose::LISTING_PHOTO);
    $stored = (string) Storage::disk('identity_private')->get($ref);

    expect($ref)->toStartWith('listing-media/'.$this->customer->customer_id.'/')->toEndWith('.encs')
        ->and(substr($stored, 0, 4))->toBe(IdentityDocumentStorage::CHUNKED_MAGIC)
        ->and(str_contains($stored, substr($plain, 0, 24)))->toBeFalse()
        ->and(implode('', iterator_to_array(app(IdentityDocumentStorage::class)->readChunked($ref), false)))->toBe($plain);
});

it('binds a token to its customer and purpose', function () {
    $token = Listings::uploadToken($this, $this->customer, 'listing_photo');
    $other = Customer::factory()->verified()->create();
    $store = app(UploadTokenStore::class);

    expect($store->resolve($token, $other->customer_id, UploadPurpose::LISTING_PHOTO))->toBeNull()
        ->and($store->resolve($token, $this->customer->customer_id, UploadPurpose::LISTING_INVOICE))->toBeNull()
        ->and($store->resolveEntry($token, $this->customer->customer_id, UploadPurpose::LISTING_PHOTO)['mime'])->toBe('image/png');
});
