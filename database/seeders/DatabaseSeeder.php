<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Dashboard roles/permissions are reference data: seeded in every environment.
        $this->call(DashboardRolesAndPermissionsSeeder::class);

        // One account per role; refuses to run outside local/testing.
        $this->call(LocalStaffSeeder::class);

        // Customers in every Users and Verification tab; same local/testing guard.
        $this->call(LocalCustomerSeeder::class);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
