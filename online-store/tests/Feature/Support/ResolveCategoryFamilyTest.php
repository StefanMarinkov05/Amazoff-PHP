<?php

declare(strict_types=1);

use App\Models\ProductCategory;
use App\Support\ResolveCategoryFamily;

/*
 * The real seeded catalogue is 4 levels deep (Clothing → Men → Tops →
 * T-Shirts), not the 2 an early shallow sample of it suggested — these
 * build a genuine multi-level tree rather than trusting that appearance,
 * and prove a grandchild is reachable, not just a direct child.
 */

it('resolves a childless category to just itself', function (): void {
    $leaf = ProductCategory::factory()->create();

    expect(ResolveCategoryFamily::selfAndDescendantIds($leaf))->toBe([$leaf->id]);
});

it('resolves a parent to itself plus every direct child', function (): void {
    $parent = ProductCategory::factory()->create();
    $childA = ProductCategory::factory()->childOf($parent)->create();
    $childB = ProductCategory::factory()->childOf($parent)->create();

    $ids = ResolveCategoryFamily::selfAndDescendantIds($parent);

    expect($ids)->toEqualCanonicalizing([$parent->id, $childA->id, $childB->id]);
});

it('resolves a grandchild, not only a direct child, at any depth', function (): void {
    $top = ProductCategory::factory()->create();          // Clothing
    $mid = ProductCategory::factory()->childOf($top)->create();   // Men
    $leaf = ProductCategory::factory()->childOf($mid)->create();  // Tops
    $grandleaf = ProductCategory::factory()->childOf($leaf)->create(); // T-Shirts

    $ids = ResolveCategoryFamily::selfAndDescendantIds($top);

    expect($ids)->toEqualCanonicalizing([$top->id, $mid->id, $leaf->id, $grandleaf->id]);
});

it('selecting a mid-level category does not pull in its own parent or siblings', function (): void {
    $top = ProductCategory::factory()->create();
    $mid = ProductCategory::factory()->childOf($top)->create();
    $sibling = ProductCategory::factory()->childOf($top)->create();
    $leaf = ProductCategory::factory()->childOf($mid)->create();

    $ids = ResolveCategoryFamily::selfAndDescendantIds($mid);

    expect($ids)->toEqualCanonicalizing([$mid->id, $leaf->id])
        ->and($ids)->not->toContain($top->id)
        ->and($ids)->not->toContain($sibling->id);
});

it('lists top-level categories with their direct children only, not grandchildren', function (): void {
    $top = ProductCategory::factory()->create();
    $child = ProductCategory::factory()->childOf($top)->create();
    ProductCategory::factory()->childOf($child)->create(); // grandchild

    $menu = ResolveCategoryFamily::topLevelWithChildren();
    $entry = $menu->firstWhere('id', $top->id);

    expect($entry->children)->toHaveCount(1)
        ->and($entry->children->first()->id)->toBe($child->id);
});
