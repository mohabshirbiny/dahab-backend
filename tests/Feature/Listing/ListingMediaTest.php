<?php

use App\Enums\ListingMediaKind;
use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\ListingMedia;
use Database\Factories\ListingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 FR-010, FR-011, research R7: a listing's files are streamed back
// decrypted, never cached; the seller and review staff see everything, the
// invoice included.

beforeEach(function () {
    Storage::fake('identity_private');
    $this->seller = Customer::factory()->verified()->create();
    $this->listing = Listing::factory()->withPhotos(2)->inReview()->create(['seller_id' => $this->seller->customer_id]);
    $this->photoBytes = Listings::pngBytes(12);
    $this->photo = ListingFactory::attachMedia($this->listing, ListingMediaKind::PHOTO, Listings::file($this->photoBytes, 'real.png'), 'image/png', 5);
    $this->invoice = ListingFactory::attachMedia($this->listing, ListingMediaKind::INVOICE, Listings::pdf(), 'application/pdf');
});

function mediaUrl(string $base, Listing $listing, ListingMedia|string $media): string
{
    return $base."/{$listing->listing_id}/media/".($media instanceof ListingMedia ? $media->media_id : $media);
}

it('streams every file of a listing to review staff, the invoice included', function (SeedRole $role) {
    Listings::actAsStaff($this, $role);

    $photo = $this->get(mediaUrl(Listings::STAFF_URL, $this->listing, $this->photo))->assertOk();
    $invoice = $this->get(mediaUrl(Listings::STAFF_URL, $this->listing, $this->invoice))->assertOk();

    expect($photo->streamedContent())->toBe($this->photoBytes)
        ->and($photo->headers->get('Content-Type'))->toStartWith('image/png')
        ->and($photo->headers->get('Cache-Control'))->toContain('no-store')
        ->and($photo->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($invoice->streamedContent())->toBe(Listings::pdfBytes())
        ->and($invoice->headers->get('Content-Type'))->toStartWith('application/pdf')
        ->and($invoice->headers->get('Cache-Control'))->toContain('no-store');
})->with([SeedRole::OPERATIONS, SeedRole::COO, SeedRole::CEO]);

it('refuses staff without a listing permission', function () {
    Listings::actAsStaff($this, SeedRole::FINANCE);

    $this->getJson(mediaUrl(Listings::STAFF_URL, $this->listing, $this->invoice))->assertForbidden()->assertJsonPath('code', 'permission_denied');
});

it('answers not found to staff for a file of another listing', function () {
    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $other = Listing::factory()->withPhotos(1)->create();

    $this->getJson(mediaUrl(Listings::STAFF_URL, $other, $this->invoice))->assertNotFound();
    $this->getJson(mediaUrl(Listings::STAFF_URL, $this->listing, (string) Str::uuid()))->assertNotFound();
});

it('streams the seller their own files, the invoice included', function () {
    $photo = Listings::as($this, $this->seller)->get(mediaUrl(Listings::SELLER_URL, $this->listing, $this->photo))->assertOk();
    $invoice = Listings::as($this, $this->seller)->get(mediaUrl(Listings::SELLER_URL, $this->listing, $this->invoice))->assertOk();

    expect($photo->streamedContent())->toBe($this->photoBytes)
        ->and($invoice->streamedContent())->toBe(Listings::pdfBytes())
        ->and($invoice->headers->get('Cache-Control'))->toContain('no-store');
});

it('hides a seller\'s files from every other customer', function () {
    $other = Customer::factory()->verified()->create();

    Listings::as($this, $other)->getJson(mediaUrl(Listings::SELLER_URL, $this->listing, $this->photo))->assertNotFound();
    Listings::as($this, $other)->getJson(mediaUrl(Listings::SELLER_URL, $this->listing, $this->invoice))->assertNotFound();
    Listings::anonymous($this)->getJson(mediaUrl(Listings::SELLER_URL, $this->listing, $this->photo))->assertUnauthorized();
});

it('does not serve the files of a listing in review on the market', function () {
    Listings::anonymous($this)->getJson(mediaUrl(Listings::MARKET_URL, $this->listing, $this->photo))->assertNotFound();
    Listings::anonymous($this)->getJson(mediaUrl(Listings::MARKET_URL, $this->listing, $this->invoice))->assertNotFound();
});
