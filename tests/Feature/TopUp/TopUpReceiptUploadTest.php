<?php

use App\Models\Customer;
use App\Models\Staff;
use App\Services\IdentityDocumentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 009 FR-011, FR-018a, research R6/R7: POST /customer/me/uploads with
// purpose=topup_receipt — images or PDF, trade gate, encrypted private store.

beforeEach(function () {
    Storage::fake('identity_private');
});

it('accepts images and PDFs as top-up receipts', function (UploadedFile $file) {
    $customer = Customer::factory()->verified()->create();

    TopUps::uploadReceipt($this, $customer, $file)
        ->assertCreated()
        ->assertJsonPath('data.purpose', 'topup_receipt')
        ->assertJsonStructure(['data' => ['upload_token', 'expires_in']]);

    $files = Storage::disk('identity_private')->allFiles("topup-receipts/{$customer->customer_id}");
    expect($files)->toHaveCount(1);
})->with([
    'png' => fn () => TopUps::receiptPng(),
    'jpeg' => fn () => UploadedFile::fake()->image('receipt.jpg', 300, 600),
    'webp' => fn () => UploadedFile::fake()->image('receipt.webp', 300, 600),
    'pdf' => fn () => TopUps::receiptPdf(),
]);

it('stores the receipt encrypted', function () {
    $customer = Customer::factory()->verified()->create();
    $pdf = TopUps::receiptPdf();
    $plain = (string) $pdf->get();

    TopUps::uploadReceipt($this, $customer, $pdf)->assertCreated();

    $ref = Storage::disk('identity_private')->allFiles("topup-receipts/{$customer->customer_id}")[0];
    expect(Storage::disk('identity_private')->get($ref))->not->toBe($plain)
        ->and(app(IdentityDocumentStorage::class)->read($ref))->toBe($plain);
});

it('refuses other file types', function () {
    $customer = Customer::factory()->verified()->create();

    TopUps::uploadReceipt($this, $customer, UploadedFile::fake()->create('receipt.txt', 2, 'text/plain'))
        ->assertStatus(422)->assertJsonValidationErrors(['file']);
});

it('refuses customers who may not add money', function (string $state, string $code) {
    $customer = $state === 'suspended'
        ? Customer::factory()->suspended(byStaffId: Staff::factory()->create()->staff_id)->create()
        : Customer::factory()->{$state}()->create();

    TopUps::uploadReceipt($this, $customer)->assertForbidden()->assertJsonPath('code', $code);

    expect(Storage::disk('identity_private')->allFiles())->toBe([]);
})->with([
    ['pendingVerification', 'verification_required'],
    ['rejected', 'verification_required'],
    ['suspended', 'account_suspended'],
]);

it('keeps identity uploads open to customers waiting for verification', function () {
    $customer = Customer::factory()->pendingVerification()->create();

    $this->bearer(TopUps::customerToken($customer))
        ->post('/api/v1/customer/me/uploads', ['purpose' => 'identity', 'file' => UploadedFile::fake()->image('id.jpg', 600, 400)], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.purpose', 'identity');
});

it('still refuses a PDF for identity uploads', function () {
    $customer = Customer::factory()->pendingVerification()->create();

    $this->bearer(TopUps::customerToken($customer))
        ->post('/api/v1/customer/me/uploads', ['purpose' => 'identity', 'file' => TopUps::receiptPdf()], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors(['file']);
});
