<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Support\ArticleFixtureLoader;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * Loads the committed article fixtures. Not called by `DatabaseSeeder`, not
 * run in CI — same reasoning as `DemoSeeder`.
 *
 * Does not seed article categories or tags itself, unlike `DemoSeeder`
 * calling `CatalogueReferenceSeeder`: those are lookup rows the catalogue
 * side does not own, and duplicating a seeder for two rows each was not
 * worth a third reference seeder. Run `CatalogueReferenceSeeder` first if
 * article categories or tags do not exist yet — `fixtures:validate-articles`
 * catches the gap either way.
 *
 * Run explicitly:
 *
 *     php artisan db:seed --class="Database\Seeders\Demo\DemoArticleSeeder"
 */
class DemoArticleSeeder extends Seeder
{
    private const DIRECTORY = 'database/fixtures/demo-articles';

    public function run(): void
    {
        $files = glob(base_path(self::DIRECTORY).'/*.json') ?: [];

        if ($files === []) {
            $this->command?->warn(
                'No fixtures in '.self::DIRECTORY.' — see reference/schema/article-fixture-format.md.'
            );

            return;
        }

        /** @var ArticleFixtureLoader $loader */
        $loader = app(ArticleFixtureLoader::class);
        $loaded = 0;

        DB::transaction(function () use ($files, $loader, &$loaded): void {
            foreach ($files as $file) {
                foreach ($this->documentsIn($file) as $document) {
                    $loader->loadArticle($document);
                    $loaded++;
                }
            }
        });

        $this->command?->info("Loaded {$loaded} article(s) from ".count($files).' fixture file(s).');
    }

    /**
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
