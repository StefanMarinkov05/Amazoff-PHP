<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\FixtureLoader;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * Loads the committed demo catalogue.
 *
 * Not called by `DatabaseSeeder` and not run in CI, per ADR-0003 — the test
 * suite wants the smallest fixture that exercises the code, and this is a few
 * hundred products written to be read by people.
 *
 * Run it explicitly:
 *
 *     php artisan db:seed --class=DemoSeeder
 *
 * `fixtures:validate` should pass first. This does not call it: a validator
 * invoked from inside the thing it validates cannot be trusted to have run,
 * and the point of ADR-0003's separate command is that a bad set is caught
 * before any row is written rather than partway through.
 *
 * One transaction for the whole set. A fixture that fails halfway leaves the
 * SKUs that already landed behind, and those block the re-run after the fix —
 * which is the specific way a half-loaded set is worse than no set.
 */
class DemoSeeder extends Seeder
{
    private const DIRECTORY = 'database/fixtures/demo';

    public function run(): void
    {
        $this->call(CatalogueReferenceSeeder::class);

        $files = glob(base_path(self::DIRECTORY).'/*.json') ?: [];

        if ($files === []) {
            $this->command?->warn(
                'No fixtures in '.self::DIRECTORY.' — see database/fixtures/SKELETON.md for how to author them.'
            );

            return;
        }

        /** @var FixtureLoader $loader */
        $loader = app(FixtureLoader::class);
        $loaded = 0;

        DB::transaction(function () use ($files, $loader, &$loaded): void {
            foreach ($files as $file) {
                foreach ($this->documentsIn($file) as $document) {
                    $loader->loadProduct($document);
                    $loaded++;
                }
            }
        });

        $this->command?->info("Loaded {$loaded} product(s) from ".count($files).' fixture file(s).');
    }

    /**
     * A file holds one product or a batch of them; both are accepted so a
     * generated batch loads without a splitting step.
     *
     * @return list<array<string, mixed>>
     */
    private function documentsIn(string $file): array
    {
        try {
            /** @var array<array-key, mixed> $decoded */
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Fixture {$file} is not valid JSON: ".$e->getMessage(), previous: $e);
        }

        return array_is_list($decoded) ? $decoded : [$decoded];
    }
}
