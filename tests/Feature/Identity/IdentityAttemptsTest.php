<?php

use App\Enums\AuditEvent;
use App\Enums\CustomerStatus;
use App\Enums\IdentityDocumentStatus;
use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use App\Notifications\CustomerVerificationRejectedNotification;
use App\Notifications\CustomerVerifiedNotification;
use App\Support\DatabaseActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Testing fixes items 2–4: every upload is its own attempt, staff decide while waiting,
// and GET /customer/me says where the check stands.

beforeEach(function () {
    Storage::fake('identity_private');
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
});

function attemptCustomer(?Customer $customer = null): Customer
{
    $customer ??= Customer::factory()->pendingVerification()->create();
    Sanctum::actingAs($customer, ['customer:access'], 'customer');

    return $customer;
}

function attemptUpload($test): string
{
    return $test->post('/api/v1/customer/me/uploads', [
        'purpose' => 'identity',
        'file' => UploadedFile::fake()->image('id.jpg', 600, 400),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.upload_token');
}

function attemptSubmit($test)
{
    return $test->postJson('/api/v1/customer/me/identity-documents', [
        'doc_kind' => 'passport',
        'front_upload_token' => attemptUpload($test),
    ]);
}

function attemptReviewer(): Staff
{
    $staff = Staff::factory()->role(SeedRole::VERIFICATION)->create();
    Sanctum::actingAs($staff, ['staff:access'], 'staff');

    return $staff;
}

it('numbers the registration document attempt 1 and shows it to staff', function () {
    $customer = attemptCustomer();
    attemptSubmit($this)->assertCreated()->assertJsonPath('data.attempt_number', 1);

    $doc = IdentityDocument::query()->sole();
    attemptReviewer();

    $this->getJson('/api/v1/dashboard/identity-documents/'.$doc->document_id)
        ->assertOk()->assertJsonPath('data.attempt_number', 1);
    $this->getJson('/api/v1/dashboard/customers/'.$customer->customer_id)
        ->assertOk()->assertJsonPath('data.latest_document.attempt_number', 1);
});

it('keeps the asked-again attempt and its images, and opens attempt 2', function () {
    $customer = attemptCustomer();
    $first = IdentityDocument::factory()->for($customer)->needsResubmission(['blurred_or_glare'])->withImage()->create();
    Storage::disk('identity_private')->assertExists($first->front_ref);

    attemptSubmit($this)->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.attempt_number', 2);

    $first->refresh();
    $second = IdentityDocument::query()->where('customer_id', $customer->customer_id)->where('attempt_number', 2)->sole();

    expect(IdentityDocument::query()->where('customer_id', $customer->customer_id)->count())->toBe(2)
        ->and($first->status)->toBe(IdentityDocumentStatus::SUPERSEDED)
        ->and($first->review_reasons)->toBe(['blurred_or_glare'])
        ->and($first->reviewed_by)->not->toBeNull()
        ->and($second->document_id)->not->toBe($first->document_id);
    Storage::disk('identity_private')->assertExists($first->front_ref);

    $audit = AuditLog::query()->where('action', AuditEvent::IDENTITY_DOCUMENT_RESUBMITTED->value)->sole();
    expect($audit->entity_id)->toBe($second->document_id)
        ->and($audit->after_json['attempt_number'])->toBe(2)
        ->and($audit->after_json['superseded_document_id'])->toBe($first->document_id);
});

it('opens attempt N+1 for a rejected customer', function () {
    $customer = attemptCustomer(Customer::factory()->rejected()->create());
    IdentityDocument::factory()->for($customer)->rejected()->create();
    IdentityDocument::factory()->for($customer)->rejected()->create();

    attemptSubmit($this)->assertCreated()->assertJsonPath('data.attempt_number', 3);

    expect(AuditLog::query()->where('action', AuditEvent::IDENTITY_DOCUMENT_SUBMITTED->value)->count())->toBe(1)
        ->and(IdentityDocument::query()->where('status', 'rejected')->count())->toBe(2);
});

it('still refuses a second upload while one is pending', function () {
    attemptCustomer();
    attemptSubmit($this)->assertCreated();
    attemptSubmit($this)->assertStatus(409)->assertJsonPath('code', 'document_already_pending');

    expect(IdentityDocument::query()->count())->toBe(1);
});

it('allows only one open document per customer at the database', function () {
    $customer = Customer::factory()->pendingVerification()->create();
    IdentityDocument::factory()->for($customer)->pending()->create();

    expect(fn () => DB::transaction(fn () => IdentityDocument::factory()->for($customer)->needsResubmission()->create()))
        ->toThrow(QueryException::class);
});

it('lets each customer read only their own attempts (forced RLS)', function () {
    $mine = Customer::factory()->pendingVerification()->create();
    $theirs = Customer::factory()->pendingVerification()->create();
    IdentityDocument::factory()->for($mine)->rejected()->create();
    IdentityDocument::factory()->for($theirs)->rejected()->create();
    IdentityDocument::factory()->for($theirs)->pending()->create();

    DatabaseActor::push('customer', $mine->customer_id);
    try {
        $seen = DB::table('identity_document')->pluck('customer_id')->unique()->values()->all();
    } finally {
        DatabaseActor::pop();
    }

    expect($seen)->toBe([$mine->customer_id]);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'needs PostgreSQL RLS');

it('lets staff reject while waiting for a new upload, and tells the customer', function () {
    Notification::fake();
    $customer = Customer::factory()->pendingVerification()->create();
    $doc = IdentityDocument::factory()->for($customer)->needsResubmission()->create();
    attemptReviewer();

    $this->postJson('/api/v1/dashboard/identity-documents/'.$doc->document_id.'/review', [
        'action' => 'reject',
        'reasons' => ['name_does_not_match'],
        'note' => 'No answer after we asked again.',
    ])->assertOk()->assertJsonPath('data.status', 'rejected');

    expect($doc->fresh()->status)->toBe(IdentityDocumentStatus::REJECTED)
        ->and($customer->fresh()->status)->toBe(CustomerStatus::REJECTED);
    Notification::assertSentTo($customer, CustomerVerificationRejectedNotification::class);
});

it('lets staff verify while waiting for a new upload', function () {
    Notification::fake();
    $customer = Customer::factory()->pendingVerification()->create();
    $doc = IdentityDocument::factory()->for($customer)->passport()->needsResubmission()->create();
    attemptReviewer();

    $this->postJson('/api/v1/dashboard/identity-documents/'.$doc->document_id.'/review', ['action' => 'verify'])
        ->assertOk()->assertJsonPath('data.status', 'verified');

    expect($customer->fresh()->status)->toBe(CustomerStatus::ACTIVE);
    Notification::assertSentTo($customer, CustomerVerifiedNotification::class);
});

it('refuses any decision on a superseded attempt', function () {
    $customer = Customer::factory()->pendingVerification()->create();
    $old = IdentityDocument::factory()->for($customer)->rejected()->create(['status' => IdentityDocumentStatus::SUPERSEDED]);
    attemptReviewer();

    $this->postJson('/api/v1/dashboard/identity-documents/'.$old->document_id.'/review', ['action' => 'verify'])
        ->assertStatus(409)->assertJsonPath('code', 'illegal_document_transition');
});

it('tells the customer where the check stands on GET /me', function (string $state, array $expect) {
    $customer = Customer::factory()->pendingVerification()->create();
    match ($state) {
        'none' => null,
        'pending' => IdentityDocument::factory()->for($customer)->pending()->create(),
        'needs_resubmission' => IdentityDocument::factory()->for($customer)->needsResubmission(['card_expired'])->create(['review_note' => 'Use a valid card.']),
        'verified' => IdentityDocument::factory()->for($customer)->verified()->create(),
        'rejected' => IdentityDocument::factory()->for($customer)->rejected()->create(['review_reasons' => ['name_does_not_match'], 'review_note' => 'Not you.']),
    };
    attemptCustomer($customer);

    $v = $this->getJson('/api/v1/customer/auth/me')->assertOk()->json('data.verification');

    expect($v['document_status'])->toBe($expect[0])
        ->and($v['reasons'])->toBe($expect[1])
        ->and($v['note'])->toBe($expect[2])
        ->and($v['can_submit'])->toBe($expect[3])
        ->and($v['attempt_number'])->toBe($state === 'none' ? null : 1);
})->with([
    'no document' => ['none', [null, [], null, true]],
    'waiting' => ['pending', ['pending', [], null, false]],
    'upload again' => ['needs_resubmission', ['needs_resubmission', ['card_expired'], 'Use a valid card.', true]],
    'verified' => ['verified', ['verified', [], null, false]],
    'not approved' => ['rejected', ['rejected', ['name_does_not_match'], 'Not you.', true]],
]);

it('shows the newest attempt on GET /me, not the superseded one', function () {
    $customer = attemptCustomer();
    IdentityDocument::factory()->for($customer)->needsResubmission()->create();
    attemptSubmit($this)->assertCreated();

    $this->getJson('/api/v1/customer/auth/me')->assertOk()
        ->assertJsonPath('data.verification.document_status', 'pending')
        ->assertJsonPath('data.verification.attempt_number', 2)
        ->assertJsonPath('data.verification.can_submit', false)
        ->assertJsonPath('data.verification.doc_kind', 'passport');
});
