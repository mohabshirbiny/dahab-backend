<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\IdentityDocument;
use App\Models\Staff;
use App\Support\Promo\CustomerIdKey;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Spec 023 T025 (research R8, D23-9): the reviewer keys the national-ID number at approval; only its
// keyed hash is stored, never returned or logged; it is the key of "once per ID" codes.

beforeEach(function () {
    Storage::fake('identity_private');
    Notification::fake();
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    Sanctum::actingAs(Staff::factory()->role(SeedRole::VERIFICATION)->create(), ['staff:access'], 'staff');
});

function reviewDoc(?IdentityDocument $doc, array $body): TestResponse
{
    return test()->postJson('/api/v1/dashboard/identity-documents/'.$doc->document_id.'/review', $body, ['Idempotency-Key' => (string) Str::uuid()]);
}

function pendingDoc(string $kind = 'egyptian_id'): IdentityDocument
{
    $customer = Customer::factory()->pendingVerification()->create();
    $factory = IdentityDocument::factory()->for($customer)->pending();

    return ($kind === 'passport' ? $factory->passport() : $factory)->create();
}

it('stores only a keyed hash of the number on a verify of an Egyptian ID, and never returns it', function () {
    $doc = pendingDoc();
    $res = reviewDoc($doc, ['action' => 'verify', 'id_number' => '29001011234567'])->assertOk();

    expect($res->getContent())->not->toContain('29001011234567')->not->toContain('id_number_hash');

    $hash = DB::table('identity_document')->where('document_id', $doc->document_id)->value('id_number_hash');
    expect($hash)->toBe(CustomerIdKey::hash('29001011234567'))
        ->and($hash)->not->toContain('29001011234567')
        ->and(strlen($hash))->toBe(64);

    // The audit row says a number was recorded and carries neither number nor hash.
    $audit = AuditLog::query()->where('entity_id', $doc->document_id)->latest('audit_id')->first();
    expect(json_encode($audit->after_json))->toContain('id_number_recorded')
        ->not->toContain('29001011234567')->not->toContain($hash);

    // The customer's key follows the hash; another account of the same number shares it.
    $customer = Customer::query()->findOrFail($doc->customer_id);
    expect(CustomerIdKey::for($customer))->toBe($hash);
});

it('ignores a number on a reject, a request for resubmission and a passport', function () {
    $rejected = pendingDoc();
    reviewDoc($rejected, ['action' => 'reject', 'reasons' => ['card_expired'], 'id_number' => '29001011234567'])->assertOk();
    $again = pendingDoc();
    reviewDoc($again, ['action' => 'request_resubmission', 'reasons' => ['blurred_or_glare'], 'id_number' => '29001011234567'])->assertOk();
    $passport = pendingDoc('passport');
    reviewDoc($passport, ['action' => 'verify', 'id_number' => '29001011234567'])->assertOk();

    expect(DB::table('identity_document')->whereIn('document_id', [$rejected->document_id, $again->document_id, $passport->document_id])
        ->whereNotNull('id_number_hash')->count())->toBe(0);

    $customer = Customer::query()->findOrFail($passport->customer_id);
    expect(CustomerIdKey::for($customer))->toBe('account:'.$customer->customer_id)
        ->and(CustomerIdKey::kind(CustomerIdKey::for($customer)))->toBe('account');
});

it('refuses a number that is not 14 digits and keeps working without one', function () {
    $doc = pendingDoc();
    reviewDoc($doc, ['action' => 'verify', 'id_number' => '2900101'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    reviewDoc($doc, ['action' => 'verify', 'id_number' => '2900101123456A'])->assertStatus(422);
    reviewDoc($doc, ['action' => 'verify'])->assertOk();

    expect(DB::table('identity_document')->where('document_id', $doc->document_id)->value('id_number_hash'))->toBeNull();
});
