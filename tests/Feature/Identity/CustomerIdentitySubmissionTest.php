<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\AuditEvent;
use App\Enums\IdentityDocumentKind;
use App\Enums\IdentityDocumentStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const SUBMIT_URL = '/api/v1/customer/me/identity-documents';

beforeEach(function () {
    Storage::fake('identity_private');
});

/** Upload an image as the currently authenticated customer and return the token. */
function uploadIdentityImage($test): string
{
    return $test->post('/api/v1/customer/me/uploads', [
        'purpose' => 'identity',
        'file' => UploadedFile::fake()->image('id.jpg', 600, 400),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.upload_token');
}

function actingAsCustomer(?Customer $customer = null): Customer
{
    $customer ??= Customer::factory()->pendingVerification()->create();
    Sanctum::actingAs($customer, ['customer:access'], 'customer');

    return $customer;
}

it('submits an Egyptian ID as pending and leaves the customer unverified', function () {
    $customer = actingAsCustomer();
    $token = uploadIdentityImage($this);

    $backToken = uploadIdentityImage($this);

    $this->postJson(SUBMIT_URL, ['doc_kind' => 'egyptian_id', 'front_upload_token' => $token, 'back_upload_token' => $backToken])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.doc_kind', 'egyptian_id')
        ->assertJsonStructure(['data' => ['document_id', 'created_at']])
        ->assertJsonMissingPath('data.front_ref');

    $document = IdentityDocument::query()->sole();
    expect($document->customer_id)->toBe($customer->customer_id)
        ->and($document->doc_kind)->toBe(IdentityDocumentKind::EGYPTIAN_ID)
        ->and($document->status)->toBe(IdentityDocumentStatus::PENDING)
        ->and($document->reviewed_by)->toBeNull()
        ->and($document->front_ref)->toStartWith("identity/{$customer->customer_id}/");
    Storage::disk('identity_private')->assertExists($document->front_ref);
    expect($customer->fresh()->is_verified)->toBeFalse();
});

it('submits a passport (a foreign customer is as valid as an Egyptian one)', function () {
    actingAsCustomer(Customer::factory()->create(['phone' => '+4915112345678']));
    $token = uploadIdentityImage($this);

    $this->postJson(SUBMIT_URL, ['doc_kind' => 'passport', 'front_upload_token' => $token])
        ->assertCreated()
        ->assertJsonPath('data.doc_kind', 'passport')
        ->assertJsonPath('data.status', 'pending');
});

it('audits the submission against the customer', function () {
    $customer = actingAsCustomer();
    $this->postJson(SUBMIT_URL, ['doc_kind' => 'passport', 'front_upload_token' => uploadIdentityImage($this)])->assertCreated();

    $row = AuditLog::query()->where('action', AuditEvent::IDENTITY_DOCUMENT_SUBMITTED->value)->sole();
    expect($row->actor_customer_id)->toBe($customer->customer_id)
        ->and($row->actor_staff_id)->toBeNull()
        ->and($row->entity_type)->toBe('identity_document')
        ->and($row->entity_id)->toBe(IdentityDocument::query()->sole()->document_id)
        ->and($row->after_json['doc_kind'])->toBe('passport');
});

it('refuses an unsupported document kind with unsupported_doc_kind and keeps the token usable', function () {
    actingAsCustomer();
    $token = uploadIdentityImage($this);

    $this->postJson(SUBMIT_URL, ['doc_kind' => 'drivers_license', 'front_upload_token' => $token])
        ->assertStatus(422)
        ->assertJsonPath('code', 'unsupported_doc_kind');
    expect(IdentityDocument::query()->count())->toBe(0);

    $this->postJson(SUBMIT_URL, ['doc_kind' => 'passport', 'front_upload_token' => $token])->assertCreated();
});

it('allows only one pending document at a time with document_already_pending', function () {
    actingAsCustomer();
    $this->postJson(SUBMIT_URL, ['doc_kind' => 'passport', 'front_upload_token' => uploadIdentityImage($this)])->assertCreated();

    $this->postJson(SUBMIT_URL, ['doc_kind' => 'passport', 'front_upload_token' => uploadIdentityImage($this)])
        ->assertStatus(409)
        ->assertJsonPath('code', 'document_already_pending');

    expect(IdentityDocument::query()->count())->toBe(1);
});

it('lets a customer resubmit after a rejection', function () {
    $customer = actingAsCustomer();
    IdentityDocument::factory()->rejected()->for($customer)->create();

    $this->postJson(SUBMIT_URL, ['doc_kind' => 'passport', 'front_upload_token' => uploadIdentityImage($this)])->assertCreated();

    expect(IdentityDocument::query()->where('customer_id', $customer->customer_id)->count())->toBe(2);
});

it('spends the upload token on submission', function () {
    $customer = actingAsCustomer();
    $token = uploadIdentityImage($this);
    $this->postJson(SUBMIT_URL, ['doc_kind' => 'passport', 'front_upload_token' => $token])->assertCreated();

    // Rejected in the meantime, so a resubmission is otherwise allowed…
    IdentityDocument::query()->where('customer_id', $customer->customer_id)->update(['status' => 'rejected']);

    // …but the same image cannot back a second document.
    $this->postJson(SUBMIT_URL, ['doc_kind' => 'passport', 'front_upload_token' => $token])
        ->assertStatus(422)
        ->assertJsonPath('code', 'upload_token_invalid');
});

it('refuses an unknown, expired, foreign or orphaned upload token identically', function (string $case) {
    $customer = actingAsCustomer();
    $token = uploadIdentityImage($this);

    match ($case) {
        'unknown' => $token = 'not-a-real-token',
        'expired' => $this->travel(config('dahab-identity.upload_token_ttl_seconds') + 1)->seconds(),
        'foreign' => actingAsCustomer(),
        'orphaned' => Storage::disk('identity_private')->deleteDirectory('identity'),
    };

    $this->postJson(SUBMIT_URL, ['doc_kind' => 'passport', 'front_upload_token' => $token])
        ->assertStatus(422)
        ->assertJsonPath('code', 'upload_token_invalid');
    expect(IdentityDocument::query()->count())->toBe(0);
})->with(['unknown', 'expired', 'foreign', 'orphaned']);

it('validates the payload', function (array $body) {
    actingAsCustomer();

    $this->postJson(SUBMIT_URL, $body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
})->with([
    'nothing' => [[]],
    'no upload_token' => [['doc_kind' => 'passport']],
    'no doc_kind' => [['front_upload_token' => 'abc']],
]);

it('requires a customer access token', function () {
    $this->postJson(SUBMIT_URL, ['doc_kind' => 'passport', 'front_upload_token' => 'x'])
        ->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

it('treats a staff token as unauthenticated', function () {
    $token = app(IssueTokenFamilyAction::class)->forStaff(Staff::factory()->create())->accessToken;

    $this->bearer($token)->postJson(SUBMIT_URL, ['doc_kind' => 'passport', 'front_upload_token' => 'x'])
        ->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

it('refuses a customer refresh token with 403 forbidden', function () {
    $session = app(IssueTokenFamilyAction::class)->forCustomer(Customer::factory()->create());

    $this->bearer($session->refreshToken)->postJson(SUBMIT_URL, ['doc_kind' => 'passport', 'front_upload_token' => 'x'])
        ->assertStatus(403)->assertJsonPath('code', 'forbidden');
});
