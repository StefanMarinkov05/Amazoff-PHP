<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\ArticleCategory;
use App\Models\Tag;
use Illuminate\Database\Seeder;

/**
 * The lookup rows an article fixture resolves its slugs against — the content
 * side's equivalent of `CatalogueReferenceSeeder`.
 *
 * Separate from that one because the two are seeded independently: a
 * catalogue-only demo needs no article categories, and an article-only
 * fixture set needs no brands. `DemoArticleSeeder` does not call this
 * automatically for the same reason `DemoSeeder` does call
 * `CatalogueReferenceSeeder` — articles reference *products* too, so the
 * ordering between the two demo seeders already has to be deliberate, and
 * hiding one inside the other would obscure it.
 *
 * `updateOrCreate` on the slug throughout: re-running corrects a renamed
 * label rather than duplicating.
 *
 * Tags are shared with products (`article_tag` and the product tag pivot both
 * point at `tags`), so the list below covers both uses.
 */
class ContentReferenceSeeder extends Seeder
{
    /** @var array<string, string> */
    private const ARTICLE_CATEGORIES = [
        'buying-guides' => 'Buying Guides',
        'how-to' => 'How-To',
        'product-news' => 'Product News',
        'workshop-tips' => 'Workshop Tips',
    ];

    /**
     * Hardware-store tags from before the catalogue became a general
     * marketplace (`power-tools`, `hand-tools`, `garden`, `cordless`), plus
     * marketplace-wide additions covering the domains
     * `sonnet-phase1-data-brief.md`'s taxonomy actually spans — added, not
     * restructured, so the original four still resolve for any article that
     * keeps discussing the hardware branch.
     *
     * @var array<string, string>
     */
    private const TAGS = [
        'buying-guides' => 'Buying Guides',
        'power-tools' => 'Power Tools',
        'hand-tools' => 'Hand Tools',
        'garden' => 'Garden',
        'safety' => 'Safety',
        'maintenance' => 'Maintenance',
        'beginner' => 'Beginner',
        'cordless' => 'Cordless',
        'clothing' => 'Clothing',
        'electronics' => 'Electronics',
        'kitchen' => 'Kitchen',
        'home' => 'Home',
        'sports' => 'Sports',
        'beauty' => 'Beauty',
        'workwear' => 'Workwear',
        'gift-guides' => 'Gift Guides',
        'seasonal' => 'Seasonal',
    ];

    public function run(): void
    {
        foreach (self::ARTICLE_CATEGORIES as $slug => $name) {
            ArticleCategory::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }

        foreach (self::TAGS as $slug => $name) {
            Tag::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
