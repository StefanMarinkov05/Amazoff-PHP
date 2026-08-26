<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\System\CarrierSeeder;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\UserSeeder;
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
        // exist first, and UserSeeder assigns roles, so those have to exist
        // before it.
        $this->call(PermissionSeeder::class);
        $this->call(RoleSeeder::class);

        // Reference data, like roles: production needs carriers to exist for
        // delivery pricing to resolve one at all. ADR-0003.
        $this->call(CarrierSeeder::class);

        // Gates itself to non-production — see the note on UserSeeder for why
        // roles seed everywhere and accounts do not.
        $this->call(UserSeeder::class);
    }
}
