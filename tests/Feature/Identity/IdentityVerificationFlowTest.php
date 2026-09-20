<?php

use App\Actions\Auth\Shared\IssueTokenFamilyAction;
use App\Enums\StaffRole;
use App\Models\Customer;
use App\Models\DocumentViewLog;
use App\Models\IdentityDocument;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('identity_private');
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
});

it('takes a customer from unverified to trade-allowed through upload, submission, view and approval', function () {
    // Customer: sign in, upload an ID image, submit it.
    $customer = Customer::factory()->pendingVerification()->create();
    $customerToken = app(IssueTokenFamilyAction::class)->forCustomer($customer)->accessToken;

    $this->bearer($customerToken)->getJson('/api/v1/customer/auth/me')
        ->assertOk()->assertJsonPath('data.is_verified', false)->assertJsonPath('data.trade_allowed', false);

    $png = pngBytes();
    $file = new UploadedFile(tap(tempnam(sys_get_temp_dir(), 'id'), fn ($p) => file_put_contents($p, $png)), 'id.png', null, null, true);
    $uploadToken = $this->bearer($customerToken)
        ->post('/api/v1/customer/me/uploads', ['purpose' => 'identity', 'file' => $file], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.upload_token');

    $backToken = $this->bearer($customerToken)
        ->post('/api/v1/customer/me/uploads', ['purpose' => 'identity', 'file' => new UploadedFile(tap(tempnam(sys_get_temp_dir(), 'idb'), fn ($p) => file_put_contents($p, $png)), 'idb.png', null, null, true)], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.upload_token');

    $documentId = $this->bearer($customerToken)
        ->postJson('/api/v1/customer/me/identity-documents', ['doc_kind' => 'egyptian_id', 'front_upload_token' => $uploadToken, 'back_upload_token' => $backToken])
        ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.document_id');

    // Staff: a real sign-in (Verification needs no MFA), then work the queue.
    $reviewer = Staff::factory()->role(StaffRole::VERIFICATION)->withPassword('correct-horse-battery')->create();
    $staffToken = $this->postJson('/api/v1/dashboard/auth/login', ['email' => $reviewer->email, 'password' => 'correct-horse-battery'])
        ->assertOk()->json('data.session.access_token');

    $this->bearer($staffToken)->getJson('/api/v1/dashboard/identity-documents')
        ->assertOk()->assertJsonPath('data.0.document_id', $documentId)->assertJsonPath('data.0.customer.display_ref', $customer->display_ref);

    $viewed = $this->bearer($staffToken)->get("/api/v1/dashboard/identity-documents/{$documentId}/image")->assertOk();
    expect($viewed->getContent())->toBe($png);
    expect(DocumentViewLog::query()->where('document_id', $documentId)->where('viewed_by', $reviewer->staff_id)->count())->toBe(1);

    $this->bearer($staffToken)->postJson("/api/v1/dashboard/identity-documents/{$documentId}/review", ['action' => 'verify'])
        ->assertOk()->assertJsonPath('data.status', 'verified');

    // Back to the customer: verified, and the trade gate is open.
    $this->bearer($customerToken)->getJson('/api/v1/customer/auth/me')
        ->assertOk()->assertJsonPath('data.is_verified', true)->assertJsonPath('data.trade_allowed', true);

    // The review queue is now empty.
    $this->bearer($staffToken)->getJson('/api/v1/dashboard/identity-documents')->assertOk()->assertJsonCount(0, 'data');
    expect(IdentityDocument::query()->sole()->reviewed_by)->toBe($reviewer->staff_id);
});

it('lets a rejected customer resubmit and be approved on the second attempt', function () {
    $customer = Customer::factory()->pendingVerification()->create();
    $customerToken = app(IssueTokenFamilyAction::class)->forCustomer($customer)->accessToken;
    $reviewer = Staff::factory()->role(StaffRole::CEO)->create();
    $staffToken = app(IssueTokenFamilyAction::class)->forStaff($reviewer)->accessToken;

    $submit = function (string $kind) use ($customerToken) {
        $token = $this->bearer($customerToken)
            ->post('/api/v1/customer/me/uploads', ['purpose' => 'identity', 'file' => UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])
            ->json('data.upload_token');

        return $this->bearer($customerToken)->postJson('/api/v1/customer/me/identity-documents', ['doc_kind' => $kind, 'front_upload_token' => $token]);
    };

    $first = $submit('passport')->assertCreated()->json('data.document_id');
    $this->bearer($staffToken)->postJson("/api/v1/dashboard/identity-documents/{$first}/review", ['action' => 'reject', 'reasons' => ['card_cut_off'], 'note' => 'Data page is cut off'])->assertOk();
    expect($customer->fresh()->is_verified)->toBeFalse();

    $second = $submit('passport')->assertCreated()->json('data.document_id');
    $this->bearer($staffToken)->postJson("/api/v1/dashboard/identity-documents/{$second}/review", ['action' => 'verify'])->assertOk();

    expect($customer->fresh()->is_verified)->toBeTrue();
});
