<?php

use App\Actions\Legal\RecordSignupAcceptancesAction;
use App\Enums\SeedRole;
use App\Exceptions\DomainApiException;
use App\Models\AgreementAcceptance;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\LegalDocument;
use App\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 019 US3: legal publishing (CEO only), acceptance counts, the required-acceptance gate
// (first acceptance for everyone — D2 — and material versions — Terms §13) and sign-up ids.

const LEGAL_URL = '/api/v1/dashboard/legal-documents';
const LEGAL_ACCEPT = '/api/v1/customer/me/legal-acceptances';

function legalPublish($test, string $code, array $body = []): TestResponse
{
    return $test->postJson(LEGAL_URL."/{$code}/versions", $body + [
        'body_en' => 'These are the terms.', 'body_ar' => 'دي الشروط.', 'is_material' => false,
    ], ['Idempotency-Key' => (string) Str::uuid()]);
}

/** A verified customer with a token, and the staff the versions are published by. */
function legalCustomer(): array
{
    $customer = Customer::factory()->verified()->create();

    return [$customer, TopUps::customerToken($customer)];
}

function legalWrite($test, string $token): TestResponse
{
    return $test->withToken($token)->postJson('/api/v1/customer/me/saved-pieces', ['listing_id' => (string) Str::uuid()], ['Idempotency-Key' => (string) Str::uuid()]);
}

it('seeds legal.publish to nobody, so only the CEO holds it (D1)', function (SeedRole $role, bool $holds) {
    $staff = TopUps::actAsStaff($this, $role);

    expect(in_array('legal.publish', $staff->effectivePermissionCodes(), true))->toBe($holds);
})->with([
    'ceo' => [SeedRole::CEO, true],
    'coo' => [SeedRole::COO, false],
    'operations' => [SeedRole::OPERATIONS, false],
    'finance' => [SeedRole::FINANCE, false],
]);

it('lets the CEO publish whole new versions, numbered one after another', function () {
    $ceo = TopUps::actAsStaff($this, SeedRole::CEO);

    legalPublish($this, 'terms')->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.code', 'terms');
    legalPublish($this, 'terms', ['body_en' => 'Second', 'body_ar' => 'التاني', 'is_material' => true])
        ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.is_material', true);

    $rows = LegalDocument::query()->where('code', 'terms')->orderBy('version')->get();
    expect($rows->pluck('version')->all())->toBe([1, 2])
        ->and($rows[0]->body_en)->toBe('These are the terms.')
        ->and($rows[1]->published_by)->toBe($ceo->staff_id);

    // The declarations are managed here too: v2 of the seeded ownership declaration.
    legalPublish($this, 'ownership_declaration')->assertCreated()->assertJsonPath('data.version', 2);

    $audit = AuditLog::query()->where('action', 'legal.published')->orderBy('audit_id')->get()[1];
    expect($audit->actor_staff_id)->toBe($ceo->staff_id)
        ->and($audit->after_json)->toMatchArray(['code' => 'terms', 'version' => 2, 'replaces_version' => 1, 'is_material' => true])
        ->and($audit->after_json['body_en']['sha256'])->toBe(hash('sha256', 'Second'))
        ->and($audit->after_json)->not->toHaveKey('body_en_text');
});

it('refuses everyone else, the COO included, and reads with content.edit', function () {
    TopUps::actAsStaff($this, SeedRole::COO);

    legalPublish($this, 'terms')->assertForbidden()->assertJsonPath('code', 'permission_denied');
    $this->getJson(LEGAL_URL)->assertOk();
    $this->getJson(LEGAL_URL.'/terms/versions')->assertOk();

    TopUps::actAsStaff($this, SeedRole::FINANCE);
    $this->getJson(LEGAL_URL)->assertForbidden();
    expect(LegalDocument::query()->where('code', 'terms')->count())->toBe(0);
});

it('requires both languages and a known code', function () {
    TopUps::actAsStaff($this, SeedRole::CEO);

    legalPublish($this, 'terms', ['body_ar' => '  '])->assertStatus(422)->assertJsonPath('code', 'legal_body_required');
    legalPublish($this, 'terms', ['body_en' => null])->assertStatus(422)->assertJsonPath('code', 'legal_body_required');
    legalPublish($this, 'not_a_document')->assertNotFound()->assertJsonPath('code', 'legal_code_unknown');
    $this->postJson(LEGAL_URL.'/terms/versions', ['body_en' => 'x'], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);

    expect(LegalDocument::query()->where('code', 'terms')->count())->toBe(0);
});

it('lists the documents with the live version and how many accepted it, and the history', function () {
    TopUps::actAsStaff($this, SeedRole::CEO);
    legalPublish($this, 'terms')->assertCreated();
    legalPublish($this, 'terms', ['body_en' => 'Second', 'body_ar' => 'التاني'])->assertCreated();
    $live = LegalDocument::current('terms');
    [$a] = legalCustomer();
    [$b] = legalCustomer();
    foreach ([$a, $b] as $c) {
        AgreementAcceptance::query()->create(['customer_id' => $c->customer_id, 'legal_doc_id' => $live->legal_doc_id, 'context' => 'signup']);
    }

    $rows = collect($this->getJson(LEGAL_URL)->assertOk()->json('data'))->keyBy('code');
    expect($rows['terms']['version'])->toBe(2)
        ->and($rows['terms']['accepted_live_count'])->toBe(2)
        ->and($rows['terms']['kind'])->toBe('document')
        ->and($rows['privacy']['published'])->toBeFalse()
        ->and($rows['ownership_declaration']['kind'])->toBe('declaration')
        ->and($rows['ownership_declaration']['version'])->toBe(1);

    $history = $this->getJson(LEGAL_URL.'/terms/versions')->assertOk()->json('data');
    expect(array_column($history, 'version'))->toBe([2, 1])
        ->and($history[0])->not->toHaveKey('body_en')
        ->and($history[0]['accepted_count'])->toBe(2);
    $this->getJson(LEGAL_URL.'/terms/versions?with_body=1')->assertJsonPath('data.1.body_en', 'These are the terms.');
    $this->getJson(LEGAL_URL.'/nope/versions')->assertNotFound();
});

it('never changes or deletes a published version (DH018)', function () {
    TopUps::actAsStaff($this, SeedRole::CEO);
    legalPublish($this, 'terms')->assertCreated();
    $doc = LegalDocument::current('terms');

    $state = function (Closure $work) {
        try {
            DB::transaction($work);
        } catch (QueryException $e) {
            return $e->errorInfo[0];
        }

        return null;
    };

    expect($state(fn () => DB::table('legal_document')->where('legal_doc_id', $doc->legal_doc_id)->update(['body_en' => 'Edited'])))->toBe('DH018')
        ->and($state(fn () => DB::table('legal_document')->where('legal_doc_id', $doc->legal_doc_id)->delete()))->toBe('DH018')
        ->and(LegalDocument::current('terms')->body_en)->toBe('These are the terms.');
});

it('keeps the public list as it was and adds the declarations', function () {
    $res = $this->getJson('/api/v1/reference/legal-documents')->assertOk();

    expect(array_column($res->json('data'), 'code'))->toBe(['terms', 'privacy', 'selling_rules', 'id_handling'])
        ->and(array_column($res->json('declarations'), 'code'))
        ->toBe(['ownership_declaration', 'deposit_agreement', 'collection_proxy_authorisation', 'payout_account_declaration'])
        ->and(collect($res->json('declarations'))->firstWhere('code', 'ownership_declaration')['published'])->toBeTrue();
});

it('asks nothing of a customer while no terms are published', function () {
    [, $token] = legalCustomer();

    $this->withToken($token)->getJson('/api/v1/customer/auth/me')->assertOk()->assertJsonPath('data.legal_acceptance_required', []);
    legalWrite($this, $token)->assertStatus(422);
});

it('gates every write on the first acceptance of the live terms until it is accepted (D2)', function () {
    TopUps::actAsStaff($this, SeedRole::CEO);
    legalPublish($this, 'terms')->assertCreated();
    legalPublish($this, 'privacy')->assertCreated();
    [$customer, $token] = legalCustomer();

    $me = $this->withToken($token)->getJson('/api/v1/customer/auth/me')->assertOk();
    $required = collect($me->json('data.legal_acceptance_required'))->keyBy('code');
    expect($required->keys()->sort()->values()->all())->toBe(['privacy', 'terms'])
        ->and($required['terms']['reason'])->toBe('first')
        ->and($required['terms']['version'])->toBe(1);

    // Reads pass; any write is refused with what is owed.
    $this->withToken($token)->getJson('/api/v1/customer/me/notifications')->assertOk();
    legalWrite($this, $token)->assertStatus(409)->assertJsonPath('code', 'legal_acceptance_required')
        ->assertJsonCount(2, 'details.required');

    // Accepting one still leaves the other.
    $this->withToken($token)->postJson(LEGAL_ACCEPT, ['legal_doc_id' => $required['terms']['legal_doc_id']], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()->assertJsonPath('data.code', 'terms')->assertJsonCount(1, 'data.legal_acceptance_required');
    legalWrite($this, $token)->assertStatus(409);

    $this->withToken($token)->postJson(LEGAL_ACCEPT, ['legal_doc_id' => $required['privacy']['legal_doc_id']], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()->assertJsonPath('data.legal_acceptance_required', []);

    // Cleared: the write now reaches its own validation.
    legalWrite($this, $token)->assertStatus(422);

    $rows = AgreementAcceptance::query()->where('customer_id', $customer->customer_id)->orderBy('accepted_at')->get();
    expect($rows)->toHaveCount(2)->and($rows->pluck('context')->unique()->all())->toBe(['first_acceptance']);
    $audit = AuditLog::query()->where('action', 'legal.accepted')->where('actor_customer_id', $customer->customer_id)->count();
    expect($audit)->toBe(2);
});

it('asks again only for a material version newer than the one accepted', function () {
    TopUps::actAsStaff($this, SeedRole::CEO);
    legalPublish($this, 'terms')->assertCreated();
    [$customer, $token] = legalCustomer();
    $v1 = LegalDocument::current('terms');
    AgreementAcceptance::query()->create(['customer_id' => $customer->customer_id, 'legal_doc_id' => $v1->legal_doc_id, 'context' => 'signup']);
    legalWrite($this, $token)->assertStatus(422);

    // A non-material v2 asks nothing.
    TopUps::actAsStaff($this, SeedRole::CEO);
    legalPublish($this, 'terms', ['body_en' => 'Minor fix', 'body_ar' => 'تعديل بسيط'])->assertCreated();
    $this->withToken($token)->getJson('/api/v1/customer/auth/me')->assertJsonPath('data.legal_acceptance_required', []);
    legalWrite($this, $token)->assertStatus(422);

    // A material v3 does, as `material` with the live id.
    TopUps::actAsStaff($this, SeedRole::CEO);
    legalPublish($this, 'terms', ['body_en' => 'Big change', 'body_ar' => 'تغيير كبير', 'is_material' => true])->assertCreated();
    $v3 = LegalDocument::current('terms');
    $me = $this->withToken($token)->getJson('/api/v1/customer/auth/me')->assertOk();
    expect($me->json('data.legal_acceptance_required'))->toBe([
        ['code' => 'terms', 'version' => 3, 'legal_doc_id' => $v3->legal_doc_id, 'reason' => 'material'],
    ]);
    legalWrite($this, $token)->assertStatus(409)->assertJsonPath('code', 'legal_acceptance_required');

    $this->withToken($token)->postJson(LEGAL_ACCEPT, ['legal_doc_id' => $v3->legal_doc_id], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    legalWrite($this, $token)->assertStatus(422);
    expect(AgreementAcceptance::query()->where('customer_id', $customer->customer_id)->where('legal_doc_id', $v3->legal_doc_id)->value('context'))->toBe('material_reaccept');
});

it('refuses to accept a stale version, a document that is not accepted this way, or one not owed', function () {
    TopUps::actAsStaff($this, SeedRole::CEO);
    legalPublish($this, 'terms')->assertCreated();
    $v1 = LegalDocument::current('terms');
    legalPublish($this, 'terms', ['body_en' => 'v2', 'body_ar' => 'ن٢'])->assertCreated();
    legalPublish($this, 'selling_rules')->assertCreated();
    [$customer, $token] = legalCustomer();
    $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];

    $this->withToken($token)->postJson(LEGAL_ACCEPT, ['legal_doc_id' => $v1->legal_doc_id], $key())->assertStatus(422)->assertJsonPath('code', 'legal_document_not_current');
    $this->withToken($token)->postJson(LEGAL_ACCEPT, ['legal_doc_id' => LegalDocument::current('selling_rules')->legal_doc_id], $key())
        ->assertStatus(422)->assertJsonPath('code', 'legal_document_not_current');
    $this->withToken($token)->postJson(LEGAL_ACCEPT, ['legal_doc_id' => 32000], $key())->assertStatus(422)->assertJsonPath('code', 'legal_document_not_current');
    $this->withToken($token)->postJson(LEGAL_ACCEPT, [], $key())->assertStatus(422)->assertJsonValidationErrors(['legal_doc_id']);

    $live = LegalDocument::current('terms');
    $this->withToken($token)->postJson(LEGAL_ACCEPT, ['legal_doc_id' => $live->legal_doc_id], $key())->assertCreated();
    $this->withToken($token)->postJson(LEGAL_ACCEPT, ['legal_doc_id' => $live->legal_doc_id], $key())
        ->assertStatus(409)->assertJsonPath('code', 'legal_acceptance_not_required');

    expect(AgreementAcceptance::query()->where('customer_id', $customer->customer_id)->count())->toBe(1);
});

it('lets a suspended customer accept, and still refuses their writes until they do', function () {
    $ceo = TopUps::actAsStaff($this, SeedRole::CEO);
    legalPublish($this, 'terms')->assertCreated();
    $customer = Customer::factory()->verified()->suspended(byStaffId: $ceo->staff_id)->create();
    $token = TopUps::customerToken($customer);
    $live = LegalDocument::current('terms');

    legalWrite($this, $token)->assertStatus(409)->assertJsonPath('code', 'legal_acceptance_required');
    $this->withToken($token)->postJson(LEGAL_ACCEPT, ['legal_doc_id' => $live->legal_doc_id], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
});

it('passes sign-out and the acceptance, and gates every other write route of the signed-in customer API', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/customer/me/') && ! in_array('GET', $r->methods(), true)
            // The acceptance is how the gate opens: it cannot sit behind it.
            && ! str_ends_with($r->uri(), 'legal-acceptances'));

    expect($routes->count())->toBeGreaterThan(30);
    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('customer.legal');
    }

    // Sign-out lives under /auth, outside the gate, so a customer can always leave.
    foreach (['api.v1.customer.auth.logout', 'api.v1.customer.auth.logout-all'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->not->toContain('customer.legal');
    }
});

it('records the terms and privacy versions the app showed at sign-up, and refuses a stale id', function () {
    TopUps::actAsStaff($this, SeedRole::CEO);
    legalPublish($this, 'terms')->assertCreated();
    $oldTerms = LegalDocument::current('terms');
    legalPublish($this, 'terms', ['body_en' => 'v2', 'body_ar' => 'ن٢'])->assertCreated();
    legalPublish($this, 'privacy')->assertCreated();
    $terms = LegalDocument::current('terms');
    $privacy = LegalDocument::current('privacy');
    $customer = Customer::factory()->create();
    $legal = app(RecordSignupAcceptancesAction::class);

    // Nothing sent: nothing recorded, no error (an older app).
    expect($legal->validate([]))->toBe([]);

    // A stale or foreign id is refused before anything is created.
    foreach ([['terms' => $oldTerms->legal_doc_id], ['terms' => $privacy->legal_doc_id], ['privacy' => $terms->legal_doc_id], ['terms' => 999999]] as $ids) {
        expect(fn () => $legal->validate($ids))->toThrow(DomainApiException::class);
    }

    $docs = $legal->validate(['terms' => $terms->legal_doc_id, 'privacy' => $privacy->legal_doc_id]);
    $legal->record($customer->customer_id, $docs, null);

    $rows = AgreementAcceptance::query()->where('customer_id', $customer->customer_id)->get();
    expect($rows)->toHaveCount(2)->and($rows->pluck('context')->unique()->all())->toBe(['signup']);

    // So the sign-in gate has nothing to ask.
    expect(Staff::query()->count())->toBeGreaterThan(0);
    $token = TopUps::customerToken($customer);
    $this->withToken($token)->getJson('/api/v1/customer/auth/me')->assertJsonPath('data.legal_acceptance_required', []);
});
