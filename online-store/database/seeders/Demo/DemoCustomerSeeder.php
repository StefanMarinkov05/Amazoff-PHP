<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Plain customers for the demo dataset — content-free, unlike `UserSeeder`'s
 * four named accounts. `UserFactory`'s defaults are already realistic
 * (`fake()->firstName()` etc.), so there is nothing here worth an LLM's
 * judgement: this is exactly the kind of state `fixture-format.md`'s
 * "What a fixture file actually contains" section says belongs in a seeder,
 * not a hand-authored document.
 *
 * `role: null` — a customer holds no role at all, the same reasoning
 * `UserSeeder`'s docblock gives for its own `customer@example.com` account.
 * Never runs in production, for the same reason `UserSeeder` does not: every
 * account here has a known password.
 */
class DemoCustomerSeeder extends Seeder
{
    /** `fixture-format.md`'s record-counts table: 50-150 customers. */
    private const COUNT = 100;

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        User::factory()->count(self::COUNT)->create();
    }
}
