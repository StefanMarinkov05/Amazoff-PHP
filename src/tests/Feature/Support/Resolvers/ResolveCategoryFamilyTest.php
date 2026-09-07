<?php

declare(strict_types=1);

use App\Models\ProductCategory;
use App\Support\Resolvers\ResolveCategoryFamily;

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

/*
 * Cycle detection. parent_id is a self-referencing foreign key and no
 * foreign key can express acyclicity — the database accepts A→B→A, after
 * which every walk of this relationship loops. ADR-0005's un-constrainable
 * class; UpdateProductCategory is what enforces it.
 */

it('does not consider moving a category to the root a cycle', function (): void {
    $category = ProductCategory::factory()->create();

    expect(ResolveCategoryFamily::wouldCreateCycle($category, null))->toBeFalse();
});

it('refuses a category as its own parent', function (): void {
    $category = ProductCategory::factory()->create();

    expect(ResolveCategoryFamily::wouldCreateCycle($category, $category->id))->toBeTrue();
});

it('refuses a category moved under its own direct child', function (): void {
    $parent = ProductCategory::factory()->create();
    $child = ProductCategory::factory()->childOf($parent)->create();

    expect(ResolveCategoryFamily::wouldCreateCycle($parent, $child->id))->toBeTrue();
});

it('refuses a category moved under a deep descendant, not only a direct child', function (): void {
    $top = ProductCategory::factory()->create();
    $mid = ProductCategory::factory()->childOf($top)->create();
    $leaf = ProductCategory::factory()->childOf($mid)->create();

    expect(ResolveCategoryFamily::wouldCreateCycle($top, $leaf->id))->toBeTrue();
});

it('allows a category moved under an unrelated category', function (): void {
    $category = ProductCategory::factory()->create();
    $elsewhere = ProductCategory::factory()->create();

    expect(ResolveCategoryFamily::wouldCreateCycle($category, $elsewhere->id))->toBeFalse();
});

it('allows a category moved under its own ancestor, which is not a cycle', function (): void {
    // Moving a leaf up to sit beside its parent is a legal reorganisation:
    // the ancestor is not in the leaf's descendant set.
    $top = ProductCategory::factory()->create();
    $mid = ProductCategory::factory()->childOf($top)->create();
    $leaf = ProductCategory::factory()->childOf($mid)->create();

    expect(ResolveCategoryFamily::wouldCreateCycle($leaf, $top->id))->toBeFalse();
});
