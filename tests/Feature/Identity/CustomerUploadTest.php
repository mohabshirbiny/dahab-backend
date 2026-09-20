<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use App\Services\IdentityDocumentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const UPLOADS_URL = '/api/v1/customer/me/uploads';

beforeEach(function () {
    Storage::fake('identity_private');
});

function idImage(string $name = 'id.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 600, 400);
}

/**
 * A real file on disk. `UploadedFile::fake()` derives the MIME type from the
 * file *name*, so it cannot exercise the content sniffing production relies on.
 */
function realFile(string $contents, string $clientName): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $clientName, null, null, true);
}

it('accepts a genuine image by its content', function () {
    Sanctum::actingAs(Customer::factory()->create(), ['customer:access'], 'customer');
    ob_start();
    imagepng(imagecreatetruecolor(8, 8));
    $png = ob_get_clean();

    $this->post(UPLOADS_URL, ['purpose' => 'identity', 'file' => realFile($png, 'scan.png')], ['Accept' => 'application/json'])
        ->assertCreated();
});

it('stores the image encrypted on the private disk and returns a single-use token', function () {
    $customer = Customer::factory()->create();
    Sanctum::actingAs($customer, ['customer:access'], 'customer');
    $file = idImage();
    $original = file_get_contents($file->getPathname());

    $response = $this->post(UPLOADS_URL, ['purpose' => 'identity', 'file' => $file], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.purpose', 'identity')
        ->assertJsonPath('data.expires_in', config('dahab-identity.upload_token_ttl_seconds'))
        ->assertJsonStructure(['data' => ['upload_token']]);

    $files = Storage::disk('identity_private')->allFiles();
    expect($files)->toHaveCount(1)
        ->and($files[0])->toStartWith("identity/{$customer->customer_id}/")->toEndWith('.enc');

    $stored = Storage::disk('identity_private')->get($files[0]);
    expect($stored)->not->toBe($original)->not->toContain('JFIF');
    expect(app(IdentityDocumentStorage::class)->read($files[0]))->toBe($original);

    // The token is the only handle: it never carries or reveals the object key.
    expect($response->getContent())->not->toContain($files[0])->not->toContain('front_ref');
    expect(IdentityDocument::query()->count())->toBe(0);
});

it('writes nothing to a publicly served disk', function () {
    Storage::fake('public');
    Sanctum::actingAs(Customer::factory()->create(), ['customer:access'], 'customer');

    $this->post(UPLOADS_URL, ['purpose' => 'identity', 'file' => idImage()], ['Accept' => 'application/json'])->assertCreated();

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('keeps the identity disk private: no url, not served, private visibility', function () {
    $disk = config('filesystems.disks.identity_private');

    expect($disk)->not->toHaveKey('url')
        ->and($disk['serve'])->toBeFalse()
        ->and($disk['visibility'])->toBe('private')
        ->and($disk['throw'])->toBeTrue();
});

it('validates the upload', function (array $payload) {
    Sanctum::actingAs(Customer::factory()->create(), ['customer:access'], 'customer');

    $this->post(UPLOADS_URL, $payload, ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    expect(Storage::disk('identity_private')->allFiles())->toBe([]);
})->with([
    'no file' => [fn () => ['purpose' => 'identity']],
    'no purpose' => [fn () => ['file' => idImage()]],
    'unknown purpose' => [fn () => ['purpose' => 'selfie', 'file' => idImage()]],
    'purpose without a consumer yet' => [fn () => ['purpose' => 'listing_photo', 'file' => idImage()]],
    'a pdf' => [fn () => ['purpose' => 'identity', 'file' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf')]],
    'a script renamed to jpg' => [fn () => ['purpose' => 'identity', 'file' => realFile('<?php echo 1;', 'id.jpg')]],
    'text renamed to png' => [fn () => ['purpose' => 'identity', 'file' => realFile('not an image at all', 'id.png')]],
    'too large' => [fn () => ['purpose' => 'identity', 'file' => idImage()->size(config('dahab-identity.max_upload_kb') + 1)]],
]);

it('requires a customer access token', function () {
    $this->postJson(UPLOADS_URL)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

it('treats a staff token as unauthenticated', function () {
    $staff = Staff::factory()->create();
    $token = app(IssueTokenFamilyAction::class)->forStaff($staff)->accessToken;

    $this->bearer($token)->postJson(UPLOADS_URL)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

it('refuses a customer refresh token with 403 forbidden', function () {
    $session = app(IssueTokenFamilyAction::class)->forCustomer(Customer::factory()->create());

    $this->bearer($session->refreshToken)->postJson(UPLOADS_URL)->assertStatus(403)->assertJsonPath('code', 'forbidden');
});

it('throttles uploads per customer', function () {
    $customer = Customer::factory()->create();
    Sanctum::actingAs($customer, ['customer:access'], 'customer');

    for ($i = 0; $i < config('dahab-identity.uploads_per_minute'); $i++) {
        $this->post(UPLOADS_URL, ['purpose' => 'identity', 'file' => idImage()], ['Accept' => 'application/json'])->assertCreated();
    }

    $this->post(UPLOADS_URL, ['purpose' => 'identity', 'file' => idImage()], ['Accept' => 'application/json'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests');
});
