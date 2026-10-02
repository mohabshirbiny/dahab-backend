<?php

use App\Enums\SeedRole;
use App\Enums\StaffPermission;
use App\Models\Staff;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// Spec 012 FR-024, research R22: every staff order endpoint is gated by its
// own catalogue code, and the seeded roles hold what Part 1 §4.1 says (the
// CEO holds every code). Editable from the Dashboard (spec 002).

it('gates every staff order endpoint by its permission', function () {
    $expected = [
        'GET api/v1/dashboard/orders' => 'order.view',
        'GET api/v1/dashboard/orders/{order}' => 'order.view',
        'POST api/v1/dashboard/orders/{order}/receive' => 'order.receive',
        'POST api/v1/dashboard/orders/{order}/inspection-results' => 'inspection.enter',
        'POST api/v1/dashboard/orders/{order}/propose-price' => 'order.price_adjust',
        'POST api/v1/dashboard/orders/{order}/change-branch' => 'order.change_branch',
        'POST api/v1/dashboard/orders/{order}/extend-deadline' => 'order.extend_deadline',
        'POST api/v1/dashboard/orders/{order}/handover' => 'order.handover',
        'POST api/v1/dashboard/orders/{order}/seller-return/handover' => 'order.handover',
        'POST api/v1/dashboard/orders/{order}/cancel' => 'order.cancel',
        'GET api/v1/dashboard/inspections' => StaffPermission::INSPECTIONS_ANY,
        'GET api/v1/dashboard/inspections/work-list' => StaffPermission::WORK_LIST_ANY,
        'GET api/v1/dashboard/buy-requests' => 'buy_request.view',
    ];

    foreach ($expected as $key => $code) {
        [$method, $uri] = explode(' ', $key);
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true));

        expect($route)->not->toBeNull("{$key} is not registered")
            ->and($route->gatherMiddleware())->toContain("staff.permission:{$code}");
    }
});

it('seeds the codes to the roles of Part 1 §4.1', function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    $codes = ['order.view', 'order.receive', 'inspection.enter', 'order.price_adjust', 'order.change_branch', 'order.extend_deadline', 'order.handover', 'buy_request.view'];

    $holds = fn (SeedRole $role) => collect($codes)->filter(fn ($c) => Staff::factory()->role($role)->create()->can($c))->values()->all();

    expect($holds(SeedRole::CEO))->toBe($codes)
        ->and($holds(SeedRole::COO))->toBe(['order.view', 'order.receive', 'order.price_adjust', 'order.change_branch', 'order.extend_deadline', 'buy_request.view'])
        ->and($holds(SeedRole::FINANCE))->toBe(['order.view'])
        ->and($holds(SeedRole::OPERATIONS))->toBe(['order.view', 'order.receive', 'order.price_adjust', 'order.change_branch', 'order.extend_deadline', 'buy_request.view'])
        ->and($holds(SeedRole::VERIFICATION))->toBe([])
        ->and($holds(SeedRole::IGI_BRANCH))->toBe(['order.receive', 'inspection.enter', 'order.handover']);
});
