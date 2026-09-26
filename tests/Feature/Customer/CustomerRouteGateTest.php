<?php

use App\Http\CustomerRouteAccess;
use Illuminate\Support\Facades\Route;

// Spec 002 FR-031 / SC-005: the customer gate is the default. A new
// authenticated customer route must be gated or deliberately allow-listed.

it('gates every authenticated customer route that is not on the allow-list', function () {
    expect(CustomerRouteAccess::ungated(Route::getRoutes()->getRoutes()))->toBe([]);
});

it('keeps the allow-list pointing at real routes', function () {
    foreach (CustomerRouteAccess::OPEN_ROUTES as $name) {
        expect(Route::has($name))->toBeTrue("{$name} is allow-listed but does not exist");
    }
});

it('reports a new customer route that forgot the gate', function () {
    Route::middleware(['api', 'auth:customer', 'abilities:customer:access'])
        ->post('/api/v1/customer/_forgotten', fn () => response()->noContent());
    Route::middleware(['api', 'auth:customer', 'abilities:customer:access', 'customer.gate:trade'])
        ->post('/api/v1/customer/_remembered', fn () => response()->noContent());

    Route::getRoutes()->refreshNameLookups();

    expect(CustomerRouteAccess::ungated(Route::getRoutes()->getRoutes()))->toBe(['POST api/v1/customer/_forgotten']);
});
