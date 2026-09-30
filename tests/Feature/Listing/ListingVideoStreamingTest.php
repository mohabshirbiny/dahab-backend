<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\ListingMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Listings;

uses(RefreshDatabase::class);

// Spec 010 FR-012, research R7 (analysis U1): a real 50 MB video is uploaded
// and played back through the API without the server ever holding the whole
// file in memory. Listing media is encrypted and served in 1 MiB chunks; the
// memory each direction may add is bounded well below the file size. If this
// bound cannot be met, the feature is not done — do not raise the bound.

const VIDEO_BYTES = 50 * 1024 * 1024;
const MEMORY_BOUND = 32 * 1024 * 1024;

/** A real file of `$bytes` bytes with MP4 content, written a megabyte at a time. */
function bigVideo(int $bytes): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'vid');
    $out = fopen($path, 'wb');
    $header = Listings::mp4Header();
    fwrite($out, $header);
    $left = $bytes - strlen($header);

    while ($left > 0) {
        $n = min($left, 1024 * 1024);
        fwrite($out, random_bytes($n));
        $left -= $n;
    }
    fclose($out);

    return new UploadedFile($path, 'piece.mp4', null, null, true);
}

/** How much the peak memory rose while `$work` ran. */
function memoryAddedBy(Closure $work): int
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $work();

    return memory_get_peak_usage() - $before;
}

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    Listings::goldPrice();
    $this->seller = Customer::factory()->verified()->create();
});

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/vid*') ?: [] as $file) {
        @unlink($file);
    }
});

it('uploads and plays back a 50 MB video without holding it in memory', function () {
    $video = bigVideo(VIDEO_BYTES);
    $sha = hash_file('sha256', $video->getRealPath());
    expect(filesize($video->getRealPath()))->toBe(VIDEO_BYTES);

    // Upload through the API.
    $token = null;
    $uploadMemory = memoryAddedBy(function () use (&$token, $video) {
        $token = Listings::upload($this, $this->seller, 'listing_video', $video)->assertCreated()->json('data.upload_token');
    });

    // Attach it to a listing, send it for review and approve it.
    $body = Listings::goldBody($this, $this->seller, ['video_token' => $token]);
    $id = Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL, $body, Listings::key())->assertCreated()->json('data.id');
    Listings::as($this, $this->seller)->postJson(Listings::SELLER_URL."/{$id}/submit", [], Listings::key())->assertOk();

    $this->withoutToken();
    Listings::actAsStaff($this, SeedRole::OPERATIONS);
    $this->postJson(Listings::STAFF_URL."/{$id}/approve", [], Listings::key())->assertOk();

    $media = ListingMedia::query()->where('listing_id', $id)->where('kind', 'video')->sole();
    $stored = Storage::disk('identity_private')->path($media->storage_ref);

    // Stored encrypted: not the same bytes, and larger than the plain file.
    expect($media->mime)->toBe('video/mp4')
        ->and(filesize($stored))->toBeGreaterThan(VIDEO_BYTES)
        ->and(hash_file('sha256', $stored))->not->toBe($sha);

    // Play it back through the public market, consuming the stream chunk by chunk.
    $response = Listings::anonymous($this)->get(Listings::MARKET_URL."/{$id}/media/{$media->media_id}")->assertOk();
    $hash = hash_init('sha256');
    $played = 0;

    $playMemory = memoryAddedBy(function () use ($response, $hash, &$played) {
        ob_start(function (string $buffer) use ($hash, &$played) {
            hash_update($hash, $buffer);
            $played += strlen($buffer);

            return '';
        }, 1024 * 1024);
        $response->baseResponse->sendContent();
        ob_end_clean();
    });

    expect($played)->toBe(VIDEO_BYTES)
        ->and(hash_final($hash))->toBe($sha)
        ->and($response->headers->get('Content-Type'))->toBe('video/mp4')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($uploadMemory)->toBeLessThan(MEMORY_BOUND)
        ->and($playMemory)->toBeLessThan(MEMORY_BOUND);
})->group('heavy');

it('refuses a video over 50 MB', function () {
    $video = bigVideo(VIDEO_BYTES + 1024 * 1024);

    Listings::upload($this, $this->seller, 'listing_video', $video)
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('file');

    expect(Storage::disk('identity_private')->allFiles())->toBe([]);
})->group('heavy');

it('holds several uploads at once within the same bound each', function () {
    // The bound is per request: three 20 MB uploads one after another add no more than one does.
    $added = [];
    foreach (range(1, 3) as $i) {
        $video = bigVideo(20 * 1024 * 1024);
        $added[] = memoryAddedBy(fn () => Listings::upload($this, $this->seller, 'listing_video', $video)->assertCreated());
    }

    expect(max($added))->toBeLessThan(MEMORY_BOUND);
})->group('heavy');
