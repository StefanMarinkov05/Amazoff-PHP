<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoDatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Loads the whole demo dataset, optionally resetting the database first.
 *
 * `Database\Seeders\Demo\DemoDatabaseSeeder` owns the ordering; this command
 * is the front door to it, and exists for two reasons a bare
 * `db:seed --class=` does not cover:
 *
 * 1. `--fresh` composes the reset and the load into the single step a demo
 *    actually needs. The two-command form is easy to half-run, and a demo
 *    set loaded onto a database that still holds the previous run's orders
 *    is the exact state this command was written to stop being normal.
 * 2. It refuses to run in production, before touching anything. Every
 *    individual `Demo*` seeder already carries that guard, but a command
 *    that resets the database should not rely on a guard living one call
 *    deeper.
 *
 * Deliberately not part of `migrate:fresh --seed`: CI needs the small
 * fixture, and this set is neither small nor fast. See ADR-0003.
 */
class SeedDemo extends Command
{
    protected $signature = 'demo:seed
        {--fresh : Drop every table and re-migrate before seeding}';

    protected $description = 'Load the full demo dataset — catalogue, customers, orders, reviews, and articles';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('demo:seed does not run in production.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->info('Resetting the database…');

            // --seed runs DatabaseSeeder, which is what puts permissions,
            // roles, carriers, and the staff accounts in place. The demo
            // seeders below assume all four exist: DemoOrderSeeder resolves
            // a carrier, and DemoReviewSeeder's approvals run as a real
            // user. Running migrate:fresh without --seed here would leave
            // both to fail several minutes into the load instead.
            $exit = Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true], $this->output);

            if ($exit !== self::SUCCESS) {
                $this->error('migrate:fresh failed — not seeding demo data on top of an unknown schema state.');

                return self::FAILURE;
            }
        }

        $this->info('Seeding the demo dataset…');

        $exit = Artisan::call('db:seed', [
            '--class' => DemoDatabaseSeeder::class,
            '--force' => true,
        ], $this->output);

        if ($exit !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Demo data loaded.');
        $this->line('Product images: run <comment>php artisan demo:fetch-images</comment> if any are missing (needs PEXELS_API_KEY).');
        $this->line('Real Stripe intents: run <comment>php artisan demo:stripe-payments</comment> (needs STRIPE_SECRET).');

        return self::SUCCESS;
    }
}
