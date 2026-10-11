<?php

use App\Enums\SeedRole;
use App\Enums\StaffPermission;
use App\Models\AppTextKey;
use App\Models\AppTextVersion;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\AppTexts;
use Tests\Support\TopUps;

uses(RefreshDatabase::class);

// Spec 019 US1 scenario 8, FR-100: only content.edit opens App text, and every write is 403 without it.

it('seeds content.edit to the CEO, the COO and Operations only', function (SeedRole $role, bool $holds) {
    $staff = TopUps::actAsStaff($this, $role);

    expect(in_array(StaffPermission::CONTENT_EDIT->value, $staff->effectivePermissionCodes(), true))->toBe($holds);
})->with([
    'ceo' => [SeedRole::CEO, true],
    'coo' => [SeedRole::COO, true],
    'operations' => [SeedRole::OPERATIONS, true],
    'finance' => [SeedRole::FINANCE, false],
    'verification' => [SeedRole::VERIFICATION, false],
    'igi branch' => [SeedRole::IGI_BRANCH, false],
]);

it('lets the roles that hold the code read and write', function (SeedRole $role) {
    TopUps::actAsStaff($this, $role);
    AppTexts::key('order.test.a');

    $this->getJson(AppTexts::URL)->assertOk();
    $this->getJson(AppTexts::URL.'/export')->assertOk();
    $this->getJson('/api/v1/dashboard/content/placeholders')->assertOk();
    AppTexts::save($this, 'order.test.a')->assertOk();
    $this->getJson(AppTexts::URL.'/order.test.a/history')->assertOk();
    AppTexts::publish($this)->assertCreated();
})->with([[SeedRole::CEO], [SeedRole::COO], [SeedRole::OPERATIONS]]);

it('refuses every route to staff without content.edit', function (SeedRole $role) {
    TopUps::actAsStaff($this, $role);
    AppTexts::key('order.test.a');
    $key = ['Idempotency-Key' => (string) Str::uuid()];

    $this->getJson(AppTexts::URL)->assertForbidden()->assertJsonPath('code', 'permission_denied');
    $this->getJson(AppTexts::URL.'/export')->assertForbidden();
    $this->getJson('/api/v1/dashboard/content/placeholders')->assertForbidden();
    $this->getJson(AppTexts::URL.'/order.test.a/history')->assertForbidden();
    $this->putJson(AppTexts::URL.'/order.test.a/draft', ['text_en' => 'A', 'text_ar' => 'ب'], $key)->assertForbidden();
    $this->deleteJson(AppTexts::URL.'/order.test.a/draft', [], $key)->assertForbidden();
    $this->postJson(AppTexts::URL.'/order.test.a/revert', ['to' => 'default'], $key)->assertForbidden();
    $this->postJson(AppTexts::URL.'/publish', [], $key)->assertForbidden();

    expect(AppTextVersion::query()->count())->toBe(0);
})->with([[SeedRole::FINANCE], [SeedRole::VERIFICATION], [SeedRole::IGI_BRANCH]]);

it('refuses visitors and customer tokens', function () {
    AppTexts::key('order.test.a');

    $this->getJson(AppTexts::URL)->assertUnauthorized();
    $this->postJson(AppTexts::URL.'/publish', [], TopUps::key())->assertUnauthorized();

    $customer = Customer::factory()->verified()->create();
    $this->bearer(TopUps::customerToken($customer))->getJson(AppTexts::URL)->assertUnauthorized();
});

it('gates every app-text route with content.edit', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'api.v1.dashboard.app-texts.')
        || $r->getName() === 'api.v1.dashboard.content.placeholders');

    expect($routes)->toHaveCount(8);
    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('staff.permission:content.edit', 'auth:staff');
        if (! in_array('GET', $route->methods(), true)) {
            expect($route->gatherMiddleware())->toContain('idempotent');
        }
    }
});

it('serves the public bundle to anyone, with no account', function () {
    AppTexts::key('order.test.a');

    $this->getJson(AppTexts::BUNDLE_URL)->assertOk();
    expect(AppTextKey::query()->count())->toBe(1);
});

it('lists the content.edit permission with its label in the catalogue', function () {
    TopUps::actAsStaff($this, SeedRole::CEO);

    $entry = collect($this->getJson('/api/v1/dashboard/permissions')->assertOk()->json('data'))->firstWhere('code', 'content.edit');

    expect($entry)->toBe(['code' => 'content.edit', 'label' => 'Edit app text', 'group' => 'Content', 'branch_scoped' => false]);
});
