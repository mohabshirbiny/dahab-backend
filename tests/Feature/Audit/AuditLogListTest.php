<?php

use App\Enums\SeedRole;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Staff;
use App\Support\SystemActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/AuditLogTestHelpers.php';

uses(RefreshDatabase::class);

// Spec 006 US1: the CEO lists, filters and opens audit entries (FR-001–FR-004, FR-010).

beforeEach(function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $this->ceo = Staff::factory()->role(SeedRole::CEO)->founder()->create(['full_name' => 'Ahmed CEO']);
    $this->finance = Staff::factory()->role(SeedRole::FINANCE)->create(['full_name' => 'Nour Finance']);
    $this->token = staffAccessToken($this->ceo);
});

it('lists entries newest first with who, what, subject, before and after', function () {
    $customer = Customer::factory()->create();
    auditRow('pricing.setting.changed', [
        'actor_staff_id' => $this->finance->staff_id, 'entity_type' => 'setting', 'reason' => 'Autumn review',
        'before_json' => ['key' => 'commission.gold_pct', 'old' => '20.0000'],
        'after_json' => ['outcome' => 'success', 'key' => 'commission.gold_pct', 'new' => '18'],
        'created_at' => now()->subMinutes(3),
    ]);
    auditRow('reference.karat.toggled', [
        'actor_staff_id' => $this->finance->staff_id, 'entity_type' => 'karat',
        'before_json' => ['is_enabled' => false], 'after_json' => ['outcome' => 'success', 'karat_code' => 22, 'is_enabled' => true],
        'created_at' => now()->subMinutes(2),
    ]);
    auditRow('auth.customer.registered', ['actor_customer_id' => $customer->customer_id, 'created_at' => now()->subMinute()]);
    auditRow('rls.system_elevation', ['actor_staff_id' => SystemActor::id()]);

    $data = $this->bearer($this->token)->getJson('/api/v1/dashboard/audit-log')->assertOk()->json('data');

    expect(collect($data)->pluck('action')->all())->toBe([
        'rls.system_elevation', 'auth.customer.registered', 'reference.karat.toggled', 'pricing.setting.changed',
    ]);
    expect($data[0]['actor'])->toBe(['type' => 'system'])
        ->and($data[1]['actor'])->toBe(['type' => 'customer', 'id' => $customer->customer_id, 'ref' => $customer->display_ref])
        ->and($data[2])->toMatchArray([
            'label' => 'Karat turned on or off', 'category' => 'reference', 'subject' => '22K',
            'before_summary' => 'Off', 'after_summary' => 'On',
        ])
        ->and($data[3])->toMatchArray([
            'label' => 'Setting changed', 'category' => 'pricing', 'subject' => 'commission.gold_pct',
            'before_summary' => '20', 'after_summary' => '18', 'reason' => 'Autumn review', 'ip' => '10.0.0.1', 'outcome' => 'success',
        ])
        ->and($data[3]['actor'])->toBe(['type' => 'staff', 'id' => $this->finance->staff_id, 'name' => 'Nour Finance'])
        ->and($data[3])->not->toHaveKey('before');
});

it('keeps sign-ins out of Everything and shows them under their own category', function () {
    auditRow('auth.staff.sign_in', ['actor_staff_id' => $this->finance->staff_id]);
    auditRow('pricing.setting.changed', ['actor_staff_id' => $this->finance->staff_id]);

    $this->bearer($this->token)->getJson('/api/v1/dashboard/audit-log')
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.action', 'pricing.setting.changed');
    $this->bearer($this->token)->getJson('/api/v1/dashboard/audit-log?category=sessions')
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.action', 'auth.staff.sign_in');
});

it('filters by period, category, actor, action and subject', function () {
    $branch = Branch::factory()->create(['name_en' => 'IGI Nasr City']);
    $entity = fake()->uuid();
    auditRow('pricing.manual_price.entered', ['actor_staff_id' => $this->finance->staff_id, 'created_at' => now()->subDays(10)]);
    auditRow('reference.branch.updated', ['actor_staff_id' => $this->ceo->staff_id, 'entity_type' => 'branch', 'after_json' => ['branch_id' => $branch->branch_id]]);
    auditRow('authz.staff.roles_changed', ['actor_staff_id' => $this->ceo->staff_id, 'entity_type' => 'staff', 'entity_id' => $entity]);
    auditRow('rls.maintenance_elevation', ['actor_staff_id' => SystemActor::id()]);
    auditRow('some.retired.code', ['actor_staff_id' => $this->ceo->staff_id]);

    $get = fn (string $q) => $this->bearer($this->token)->getJson('/api/v1/dashboard/audit-log?'.$q)->assertOk()->json();

    expect($get('')['meta']['total'])->toBe(4) // the 10-day-old entry is outside the default 7 days
        ->and($get('from='.now()->subDays(11)->toDateString())['meta']['total'])->toBe(5)
        ->and($get('category=reference')['data'][0]['subject'])->toBe('IGI Nasr City')
        ->and(collect($get('category=system')['data'])->pluck('action')->sort()->values()->all())->toBe(['rls.maintenance_elevation', 'some.retired.code'])
        ->and($get('actor=system')['meta']['total'])->toBe(1)
        ->and($get('actor='.$this->ceo->staff_id)['meta']['total'])->toBe(3)
        ->and($get('action=authz.staff.roles_changed')['meta']['total'])->toBe(1)
        ->and($get('entity_type=staff&entity_id='.$entity)['meta']['total'])->toBe(1)
        ->and($get('category=money')['data'])->toBe([]);

    $legacy = collect($get('category=system')['data'])->firstWhere('action', 'some.retired.code');
    expect($legacy['label'])->toBe('some.retired.code')->and($legacy['category'])->toBe('system');
});

it('refuses bad filters', function (string $query, string $field) {
    $this->bearer($this->token)->getJson('/api/v1/dashboard/audit-log?'.$query)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'category' => ['category=wallets', 'category'],
    'date' => ['from=27-09-2026', 'from'],
    'order' => ['from=2026-09-20&to=2026-09-10', 'to'],
    'actor' => ['actor=bob', 'actor'],
    'cursor' => ['cursor=not-a-cursor', 'cursor'],
    'per_page' => ['per_page=500', 'per_page'],
]);

it('pages with a cursor without missing or repeating entries, even when new ones arrive', function () {
    foreach (range(1, 120) as $i) {
        auditRow('pricing.setting.changed', ['actor_staff_id' => $this->finance->staff_id, 'created_at' => now()->subSeconds(200 - $i)]);
    }

    $first = $this->bearer($this->token)->getJson('/api/v1/dashboard/audit-log?per_page=50')->assertJsonPath('meta.total', 120)->json();
    auditRow('pricing.setting.changed', ['actor_staff_id' => $this->finance->staff_id]); // newest, arrives between pages
    $second = $this->bearer($this->token)->getJson('/api/v1/dashboard/audit-log?per_page=50&cursor='.$first['meta']['next_cursor'])->json();
    $third = $this->bearer($this->token)->getJson('/api/v1/dashboard/audit-log?per_page=50&cursor='.$second['meta']['next_cursor'])->json();

    $ids = collect([$first, $second, $third])->flatMap(fn ($page) => collect($page['data'])->pluck('id'));
    expect($ids)->toHaveCount(120)->and($ids->unique())->toHaveCount(120)
        ->and($third['meta']['next_cursor'])->toBeNull();
});

it('opens one entry with everything recorded for it', function () {
    $id = auditRow('pricing.setting.changed', [
        'actor_staff_id' => $this->finance->staff_id, 'reason' => 'Autumn review',
        'before_json' => ['key' => 'vat.pct', 'old' => '14.0000'], 'after_json' => ['outcome' => 'success', 'key' => 'vat.pct', 'new' => '15'],
    ]);

    $this->bearer($this->token)->getJson("/api/v1/dashboard/audit-log/{$id}")
        ->assertOk()
        ->assertJsonPath('data.before', ['key' => 'vat.pct', 'old' => '14.0000'])
        ->assertJsonPath('data.after.new', '15')
        ->assertJsonPath('data.device_fingerprint', 'fp-test')
        ->assertJsonPath('data.reason', 'Autumn review');

    $this->bearer($this->token)->getJson('/api/v1/dashboard/audit-log/999999')->assertNotFound();
});

it('lists the categories and which ones Everything includes', function () {
    $data = $this->bearer($this->token)->getJson('/api/v1/dashboard/audit-log/categories')->assertOk()->json('data');

    expect(collect($data)->pluck('value')->all())->toBe(['money', 'pricing', 'accounts', 'identity', 'promo', 'reference', 'listings', 'orders', 'sessions', 'system'])
        ->and(collect($data)->firstWhere('value', 'sessions')['in_everything'])->toBeFalse();
});

it('never puts personal fields in the summaries, only in the details', function () {
    $customer = Customer::factory()->create();
    $id = auditRow('auth.customer.registration_submitted', [
        'actor_customer_id' => $customer->customer_id,
        'after_json' => ['outcome' => 'success', 'phone' => '+201000000000', 'doc_kind' => 'egyptian_id'],
    ]);
    auditRow('some.other.code', [
        'actor_staff_id' => $this->ceo->staff_id,
        'after_json' => ['outcome' => 'success', 'email' => 'someone@example.com', 'status' => 'ok'],
    ]);

    $everything = $this->bearer($this->token)->getJson('/api/v1/dashboard/audit-log')->json('data');
    $system = $this->bearer($this->token)->getJson('/api/v1/dashboard/audit-log?category=system')->json('data');
    $raw = json_encode($everything).json_encode($system);

    expect($raw)->not->toContain('+201000000000')->and($raw)->not->toContain('someone@example.com')
        ->and(collect($everything)->firstWhere('id', $id)['after_summary'])->toBe('Egyptian id');

    $this->bearer($this->token)->getJson("/api/v1/dashboard/audit-log/{$id}")->assertJsonPath('data.after.phone', '+201000000000');
});
