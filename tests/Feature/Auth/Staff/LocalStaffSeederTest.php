<?php

use App\Enums\SeedRole;
use App\Models\Staff;
use Database\Seeders\LocalStaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates one account per role with its Spatie role, idempotently', function () {
    $this->seed(LocalStaffSeeder::class);
    $this->seed(LocalStaffSeeder::class);

    expect(Staff::query()->manageable()->count())->toBe(count(SeedRole::cases()));

    foreach (SeedRole::cases() as $role) {
        $staff = Staff::where('email', "{$role->value}@dahab.test")->sole();

        expect($staff->getRoleNames()->all())->toBe([$role->value])
            ->and($staff->is_founder)->toBe(in_array($role, [SeedRole::CEO, SeedRole::COO], true))
            ->and($staff->branch_id)->toBe($role === SeedRole::IGI_BRANCH ? 1 : null);
    }

    expect(Staff::where('email', 'ceo@dahab.test')->sole()->can('customer.suspend'))->toBeTrue()
        ->and(Staff::where('email', 'operations@dahab.test')->sole()->can('customer.suspend'))->toBeFalse();
});

it('stores a hashed password and does not overwrite a changed one on reseed', function () {
    $this->seed(LocalStaffSeeder::class);
    $staff = Staff::where('email', 'ceo@dahab.test')->sole();

    $hash = DB::table('staff_password')->where('staff_id', $staff->staff_id)->value('password_hash');
    expect(Hash::check(config('dahab-auth.local_seed_password'), $hash))->toBeTrue();

    $changed = Hash::make('a-developer-changed-this');
    DB::table('staff_password')->where('staff_id', $staff->staff_id)->update(['password_hash' => $changed]);

    $this->seed(LocalStaffSeeder::class);

    expect(DB::table('staff_password')->where('staff_id', $staff->staff_id)->value('password_hash'))->toBe($changed);
});

it('refuses to seed accounts outside local and testing', function (string $environment) {
    $this->app['env'] = $environment;

    // --force is how a deploy invokes seeders in production; the seeder itself must still refuse.
    $this->artisan('db:seed', ['--class' => LocalStaffSeeder::class, '--force' => true])->assertSuccessful();

    expect(Staff::query()->manageable()->count())->toBe(0)
        ->and(DB::table('staff_password')->count())->toBe(0);
})->with(['production', 'staging']);
