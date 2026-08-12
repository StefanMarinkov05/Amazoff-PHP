<?php

declare(strict_types=1);

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
        // Order matters: RoleSeeder attaches permissions, so they have to
        // exist first.
        $this->call(PermissionSeeder::class);
        $this->call(RoleSeeder::class);

        // A seeded credential is only safe outside production — see
        // docs/adr/0003-seeding-data.md, which lists roles but not staff
        // accounts as production reference data.
        if (! app()->isProduction()) {
            $admin = User::factory()->create([
                'first_name' => 'Admin',
                'last_name' => 'User',
                'email' => 'admin@example.com',
            ]);

            $admin->assignRole('administrator');
        }
    }
}
