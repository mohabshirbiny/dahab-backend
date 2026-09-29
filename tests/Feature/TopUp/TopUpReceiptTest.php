<?php

use App\Enums\SeedRole;
use App\Models\Customer;
use App\Models\TopUp;
use App\Services\IdentityDocumentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 009 FR-011: GET /dashboard/topups/{topup}/receipt — decrypted, with its
// type, never cached; staff with topup.match only.

beforeEach(function () {
    Storage::fake('identity_private');
    $this->finance = TopUps::actAsStaff($this, SeedRole::FINANCE);
});

it('streams the decrypted receipt with its content type', function (string $mime, string $bytes) {
    $customer = Customer::factory()->verified()->create();
    $ref = "topup-receipts/{$customer->customer_id}/r.enc";
    app(IdentityDocumentStorage::class)->putAt($ref, $bytes);
    $topUp = TopUp::factory()->withReceipt($ref, $mime)->create(['customer_id' => $customer->customer_id]);

    $res = $this->get("/api/v1/dashboard/topups/{$topUp->topup_id}/receipt")->assertOk();

    expect($res->headers->get('content-type'))->toBe($mime)
        ->and($res->headers->get('cache-control'))->toContain('no-store')
        ->and($res->getContent())->toBe($bytes);
})->with([
    'png' => ['image/png', 'PNG-BYTES'],
    'pdf' => ['application/pdf', "%PDF-1.4\n%%EOF\n"],
]);

it('answers 404 when the notice has no receipt', function () {
    $topUp = TopUp::factory()->create();

    $this->get("/api/v1/dashboard/topups/{$topUp->topup_id}/receipt", ['Accept' => 'application/json'])->assertNotFound();
});

it('refuses staff without the match permission', function () {
    $topUp = TopUp::factory()->withReceipt()->create();
    TopUps::actAsStaff($this, SeedRole::OPERATIONS);

    $this->get("/api/v1/dashboard/topups/{$topUp->topup_id}/receipt", ['Accept' => 'application/json'])
        ->assertForbidden()->assertJsonPath('code', 'permission_denied');
});
