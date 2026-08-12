<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Reference data needed in every environment, production included — a
     * role must exist before anyone can be assigned to it through the panel.
     */
    public function run(): void
    {
        foreach (User::STAFF_ROLES as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}
