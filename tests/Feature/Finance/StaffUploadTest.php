<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Finance;
use Tests\Support\Listings;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 015 research R5: a staff member's proof upload for a bank movement —
// PDF/JPG/PNG up to 10 MB, encrypted, a single-use token tied to the uploader.

beforeEach(fn () => Storage::fake('identity_private'));

const STAFF_UPLOAD_URL = '/api/v1/dashboard/uploads';

it('stores a proof encrypted and returns a token', function (string $name) {
    Finance::staff($this, SeedRole::FINANCE);
    $file = match ($name) {
        'pdf' => Listings::pdf(),
        'png' => Listings::png(),
        'jpg' => Listings::jpeg(),
    };

    $this->post(STAFF_UPLOAD_URL, ['purpose' => 'bank_movement_proof', 'file' => $file], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonStructure(['data' => ['token', 'expires_in']]);
    expect(Storage::disk('identity_private')->allFiles('bank-proofs'))->toHaveCount(1);
})->with(['pdf', 'png', 'jpg']);

it('refuses other files, sizes and purposes', function () {
    Finance::staff($this, SeedRole::FINANCE);

    $this->post(STAFF_UPLOAD_URL, ['purpose' => 'bank_movement_proof', 'file' => UploadedFile::fake()->create('x.exe', 10)], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('file');
    $this->post(STAFF_UPLOAD_URL, ['purpose' => 'bank_movement_proof', 'file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('file');
    $this->post(STAFF_UPLOAD_URL, ['purpose' => 'identity', 'file' => Listings::pdf()], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('purpose');
});

it('needs bank.record; a customer can never upload a bank proof', function () {
    foreach ([SeedRole::COO, SeedRole::OPERATIONS] as $role) {
        Finance::staff($this, $role);
        $this->post(STAFF_UPLOAD_URL, ['purpose' => 'bank_movement_proof', 'file' => Listings::pdf()], ['Accept' => 'application/json'])->assertForbidden();
    }

    app('auth')->forgetGuards();
    $customer = Customer::factory()->verified()->create();
    $this->withToken(TopUps::customerToken($customer))
        ->post('/api/v1/customer/me/uploads', ['purpose' => 'bank_movement_proof', 'file' => Listings::pdf()], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('purpose');
});

it('ties the token to the uploader: another staff member cannot use it', function () {
    Finance::staff($this, SeedRole::FINANCE);
    $token = Finance::proofToken($this);

    Finance::staff($this, SeedRole::FINANCE);
    Finance::record($this, 'bank_charge', 'out', '250', ['proof_upload_token' => $token])
        ->assertStatus(422)->assertJsonPath('code', 'upload_token_invalid');
});
