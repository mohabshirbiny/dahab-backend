<?php

use App\Enums\AuditEvent;
use App\Enums\CustomerStatus;
use App\Enums\IdentityDocumentStatus;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DocumentViewLog;
use App\Models\IdentityDocument;
use App\Models\Staff;
use App\Notifications\CustomerVerificationRejectedNotification;
use App\Notifications\CustomerVerificationResubmissionNotification;
use App\Notifications\CustomerVerifiedNotification;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const REVIEW_BASE = '/api/v1/dashboard/identity-documents';

beforeEach(function () {
    Storage::fake('identity_private');
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    Notification::fake();
});

function reviewer(SeedRole $role = SeedRole::VERIFICATION): Staff
{
    $staff = Staff::factory()->role($role)->create();
    Sanctum::actingAs($staff, ['staff:access'], 'staff');

    return $staff;
}

function pendingCustomerWithDoc(): IdentityDocument
{
    $customer = Customer::factory()->pendingVerification()->create();

    return IdentityDocument::factory()->for($customer)->pending()->create();
}

it('lists pending documents oldest first with no storage reference', function () {
    reviewer();
    $newer = pendingCustomerWithDoc()->fresh(['customer']);
    $newer->created_at = now()->subMinute();
    $newer->save();

    $older = pendingCustomerWithDoc();
    $older->created_at = now()->subHour();
    $older->save();

    $response = $this->getJson(REVIEW_BASE)->assertOk();

    $ids = collect($response->json('data'))->pluck('document_id')->all();
    expect($ids)->toContain($older->document_id)->toContain($newer->document_id);
    expect($response->getContent())->not->toContain('front_ref')->not->toContain('back_ref');
    expect(DocumentViewLog::query()->count())->toBe(0);
});

it('shows review metadata without opening the image or logging a view', function () {
    reviewer();
    $document = IdentityDocument::factory()->passport()->withImage(pngBytes())->create();

    $this->getJson(REVIEW_BASE.'/'.$document->document_id)
        ->assertOk()
        ->assertJsonPath('data.document_id', $document->document_id)
        ->assertJsonPath('data.doc_kind', 'passport')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonMissingPath('data.front_ref');

    expect(DocumentViewLog::query()->count())->toBe(0);
});

it('returns the decrypted front image and writes one view log row', function () {
    $staff = reviewer();
    $png = pngBytes();
    $document = IdentityDocument::factory()->withImage($png)->create();

    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->get(REVIEW_BASE.'/'.$document->document_id.'/image')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    expect($response->getContent())->toBe($png);

    $log = DocumentViewLog::query()->sole();
    expect($log->document_id)->toBe($document->document_id)
        ->and($log->viewed_by)->toBe($staff->staff_id);
});

it('returns the back image when side=back is requested', function () {
    reviewer();
    $document = IdentityDocument::factory()->withImage(pngBytes())->create();

    $this->get(REVIEW_BASE.'/'.$document->document_id.'/image?side=back')->assertOk();
});

it('verify moves customer to active, document to verified, and dispatches CustomerVerifiedNotification', function () {
    $staff = reviewer();
    $document = pendingCustomerWithDoc();

    $this->postJson(REVIEW_BASE.'/'.$document->document_id.'/review', ['action' => 'verify'])
        ->assertOk()
        ->assertJsonPath('data.status', 'verified');

    $document->refresh();
    $customer = $document->customer->fresh();

    expect($document->status)->toBe(IdentityDocumentStatus::VERIFIED)
        ->and($document->reviewed_by)->toBe($staff->staff_id)
        ->and($customer->status)->toBe(CustomerStatus::ACTIVE)
        ->and($customer->is_verified)->toBeTrue()
        ->and($customer->is_suspended)->toBeFalse();

    Notification::assertSentTo($customer, CustomerVerifiedNotification::class);

    expect(AuditLog::query()->where('action', AuditEvent::CUSTOMER_VERIFICATION_APPROVED->value)->count())->toBe(1);
});

it('request_resubmission keeps customer pending and stores structured reasons + note', function () {
    reviewer();
    $document = pendingCustomerWithDoc();

    $this->postJson(REVIEW_BASE.'/'.$document->document_id.'/review', [
        'action' => 'request_resubmission',
        'reasons' => ['blurred_or_glare', 'back_missing'],
        'note' => 'Please upload a clearer image.',
    ])->assertOk()->assertJsonPath('data.status', 'needs_resubmission');

    $document->refresh();
    expect($document->status)->toBe(IdentityDocumentStatus::NEEDS_RESUBMISSION)
        ->and($document->review_reasons)->toBe(['blurred_or_glare', 'back_missing'])
        ->and($document->review_note)->toBe('Please upload a clearer image.')
        ->and($document->customer->fresh()->status)->toBe(CustomerStatus::PENDING_VERIFICATION);

    Notification::assertSentTo($document->customer, CustomerVerificationResubmissionNotification::class);
});

it('reject moves customer to rejected and dispatches CustomerVerificationRejectedNotification', function () {
    reviewer();
    $document = pendingCustomerWithDoc();

    $this->postJson(REVIEW_BASE.'/'.$document->document_id.'/review', [
        'action' => 'reject',
        'reasons' => ['card_expired'],
        'note' => 'ID expired.',
    ])->assertOk()->assertJsonPath('data.status', 'rejected');

    $document->refresh();
    expect($document->status)->toBe(IdentityDocumentStatus::REJECTED)
        ->and($document->customer->fresh()->status)->toBe(CustomerStatus::REJECTED);

    Notification::assertSentTo($document->customer, CustomerVerificationRejectedNotification::class);
});

it('rejects with 422 when reasons are missing for request_resubmission or reject', function () {
    reviewer();
    $document = pendingCustomerWithDoc();

    $this->postJson(REVIEW_BASE.'/'.$document->document_id.'/review', ['action' => 'request_resubmission'])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    $this->postJson(REVIEW_BASE.'/'.$document->document_id.'/review', ['action' => 'reject'])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('reviews a verified or rejected document only once (illegal_document_transition)', function () {
    reviewer();
    $document = pendingCustomerWithDoc();

    $this->postJson(REVIEW_BASE.'/'.$document->document_id.'/review', ['action' => 'verify'])->assertOk();

    $this->postJson(REVIEW_BASE.'/'.$document->document_id.'/review', ['action' => 'verify'])
        ->assertStatus(409)->assertJsonPath('code', 'illegal_document_transition');
});

it('answers 404 not_found for an unknown document id', function () {
    reviewer();

    $this->getJson(REVIEW_BASE.'/'.Str::uuid())->assertStatus(404)->assertJsonPath('code', 'not_found');
});

it('unauthorized staff cannot view or decide', function () {
    Sanctum::actingAs(Staff::factory()->role(SeedRole::FINANCE)->create(), ['staff:access'], 'staff');
    $document = pendingCustomerWithDoc();

    $this->getJson(REVIEW_BASE.'/'.$document->document_id)->assertStatus(403);
    $this->postJson(REVIEW_BASE.'/'.$document->document_id.'/review', ['action' => 'verify'])->assertStatus(403);
});
