<?php

use App\Enums\SeedRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Staff;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Database\Seeders\DashboardRolesAndPermissionsSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

uses(RefreshDatabase::class);

// Spec 003 US3: legitimate cross-customer work keeps working, only through
// named elevations, and system/maintenance elevations are audited (Q2).

final class CountCustomersProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static ?int $seen = null;

    public static ?string $scope = null;

    public function handle(): void
    {
        self::$scope = DatabaseActor::scope();
        self::$seen = (int) DB::selectOne('SELECT count(*) AS n FROM customer')->n;
    }
}

beforeEach(fn () => SystemActor::forget());

it('lets staff with customer.view see every customer', function () {
    $this->seed(DashboardRolesAndPermissionsSeeder::class);
    Customer::factory()->count(3)->pendingVerification()->create();

    $this->bearer(staffAccessToken(Staff::factory()->role(SeedRole::VERIFICATION)->create()))
        ->getJson('/api/v1/dashboard/customers?status=pending_verification')
        ->assertOk()->assertJsonCount(3, 'data');
});

it('runs a queued job as the system actor across customers and audits the elevation once', function () {
    Customer::factory()->count(2)->create();
    DatabaseActor::push('');

    CountCustomersProbeJob::dispatch();

    expect(CountCustomersProbeJob::$scope)->toBe('system')
        ->and(CountCustomersProbeJob::$seen)->toBe(2)
        ->and(DatabaseActor::scope())->toBe('');
    DatabaseActor::pop();

    $audit = AuditLog::query()->where('action', 'rls.system_elevation')->sole();
    expect($audit->actor_staff_id)->toBe(SystemActor::id())
        ->and($audit->after_json['job'])->toBe(CountCustomersProbeJob::class);
});

it('elevates a CLI seeding run to maintenance, audits it, and restores the scope', function () {
    // Laravel fires the console events only for real `php artisan` runs (not
    // Artisan::call), so the listener is driven with the same events here.
    DatabaseActor::push('');
    $input = new ArrayInput([]);
    $output = new NullOutput;

    event(new CommandStarting('db:seed', $input, $output));
    expect(DatabaseActor::scope())->toBe('maintenance');

    event(new CommandFinished('db:seed', $input, $output, 0));
    expect(DatabaseActor::scope())->toBe('');
    DatabaseActor::pop();

    $audit = AuditLog::query()->where('action', 'rls.maintenance_elevation')->sole();
    expect($audit->actor_staff_id)->toBe(SystemActor::id())
        ->and($audit->after_json['command'])->toBe('db:seed');

    event(new CommandStarting('route:list', $input, $output));
    expect(DatabaseActor::scope())->toBe('maintenance'); // unchanged: not a maintenance command
});

it('only elevates the pinned unauthenticated auth routes', function () {
    $elevated = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('db.elevate:bootstrap', $r->gatherMiddleware(), true))
        ->map(fn ($r) => $r->getName())->sort()->values()->all();

    expect($elevated)->toBe([
        'api.v1.customer.auth.login',
        'api.v1.customer.auth.otp.resend',
        'api.v1.customer.auth.otp.verify',
        'api.v1.customer.auth.register.complete',
        'api.v1.customer.auth.register.documents',
        'api.v1.customer.auth.register.email',
        'api.v1.customer.auth.register.start',
        'api.v1.customer.auth.register.submit',
        'api.v1.customer.auth.register.verify-email-otp',
        'api.v1.customer.auth.register.verify-phone-otp',
        'api.v1.dashboard.auth.login',
        'api.v1.dashboard.auth.mfa.enroll',
        'api.v1.dashboard.auth.mfa.verify',
    ]);

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $mw = $route->gatherMiddleware();
        if (in_array('db.elevate:bootstrap', $mw, true)) {
            expect(collect($mw)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth:')))
                ->toBeFalse("{$route->uri()} is authenticated and must not elevate");
        }
    }
});
