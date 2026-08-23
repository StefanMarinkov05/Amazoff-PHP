<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ArticleStatus;
use App\Models\ArticleCategory;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Console\Command;
use JsonException;

/**
 * Checks an article fixture set before anything writes a row. Same reasoning
 * as `fixtures:validate`: report every problem at once, with a path, before
 * a bad set is half-loaded.
 *
 * A separate command from `fixtures:validate` rather than one command
 * handling both shapes — articles reference authors, tags, and products,
 * none of which a product fixture cares about, and merging the two would
 * make both harder to read for no reuse beyond "reads a directory of JSON".
 */
final class ValidateArticleFixtures extends Command
{
    protected $signature = 'fixtures:validate-articles {path=database/fixtures/demo-articles : Directory of JSON fixture documents}';

    protected $description = 'Validate article fixture documents before seeding';

    /** @var list<string> */
    private array $failures = [];

    /** @var array<string, string> */
    private array $slugs = [];

    public function handle(): int
    {
        $directory = base_path((string) $this->argument('path'));

        if (! is_dir($directory)) {
            $this->error("No such directory: {$directory}");

            return self::FAILURE;
        }

        $files = glob($directory.'/*.json') ?: [];

        if ($files === []) {
            $this->error("No .json documents in {$directory}");

            return self::FAILURE;
        }

        $authorEmails = User::query()->pluck('email')->all();
        $categorySlugs = ArticleCategory::query()->pluck('slug')->all();
        $tagSlugs = Tag::query()->pluck('slug')->all();
        $productSlugs = Product::query()->pluck('slug')->all();

        foreach ($files as $file) {
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            try {
                /** @var array<array-key, mixed> $decoded */
                $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->recordFailure($relative, 'is not valid JSON: '.$e->getMessage());

                continue;
            }

            $isBatch = array_is_list($decoded);

            foreach ($this->documentsIn($decoded) as $index => $document) {
                $path = $isBatch ? "{$relative}[{$index}]" : $relative;

                $this->validateDocument($path, $document, $authorEmails, $categorySlugs, $tagSlugs, $productSlugs);
            }
        }

        if ($this->failures !== []) {
            foreach ($this->failures as $failure) {
                $this->line("<fg=red>✗</> {$failure}");
            }

            $this->newLine();
            $this->error(count($this->failures).' problem(s) across '.count($files).' document(s).');

            return self::FAILURE;
        }

        $this->info(count($files).' document(s) valid.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $authorEmails
     * @param  list<string>  $categorySlugs
     * @param  list<string>  $tagSlugs
     * @param  list<string>  $productSlugs
     */
    private function validateDocument(
        string $path,
        array $document,
        array $authorEmails,
        array $categorySlugs,
        array $tagSlugs,
        array $productSlugs,
    ): void {
        foreach (['title', 'slug', 'content', 'author'] as $required) {
            if (! isset($document[$required])) {
                $this->recordFailure($path, "is missing required key [{$required}]");

                return;
            }
        }

        $this->assertUniqueSlug($path, (string) $document['slug']);

        if (! in_array($document['author'], $authorEmails, true)) {
            $this->recordFailure($path, "references unknown author email [{$document['author']}]");
        }

        if (isset($document['category']) && ! in_array($document['category'], $categorySlugs, true)) {
            $this->recordFailure($path, "references unknown article category slug [{$document['category']}]");
        }

        foreach ($document['tags'] ?? [] as $tag) {
            if (! in_array($tag, $tagSlugs, true)) {
                $this->recordFailure($path, "references unknown tag slug [{$tag}]");
            }
        }

        foreach ($document['related_products'] ?? [] as $product) {
            if (! in_array($product, $productSlugs, true)) {
                $this->recordFailure($path, "references unknown product slug [{$product}]");
            }
        }

        if (isset($document['status'])) {
            $status = ArticleStatus::tryFrom((string) $document['status']);

            if ($status === null) {
                $valid = implode(', ', array_map(fn (ArticleStatus $s): string => $s->value, ArticleStatus::cases()));
                $this->recordFailure($path, "has status [{$document['status']}], must be one of: {$valid}");
            }
        }

        foreach (['title' => 100, 'slug' => 100, 'summary' => 255, 'seo_title' => 100, 'seo_description' => 255] as $field => $max) {
            if (isset($document[$field]) && mb_strlen((string) $document[$field]) > $max) {
                $this->recordFailure($path, "[{$field}] exceeds {$max} characters");
            }
        }
    }

    private function assertUniqueSlug(string $path, string $slug): void
    {
        if (isset($this->slugs[$slug])) {
            $this->recordFailure($path, "slug [{$slug}] already used in {$this->slugs[$slug]}");

            return;
        }

        $this->slugs[$slug] = $path;
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return list<array<string, mixed>>
     */
    private function documentsIn(array $decoded): array
    {
        return array_is_list($decoded) ? $decoded : [$decoded];
    }

    private function recordFailure(string $path, string $problem): void
    {
        $this->failures[] = "{$path} {$problem}";
    }
}
