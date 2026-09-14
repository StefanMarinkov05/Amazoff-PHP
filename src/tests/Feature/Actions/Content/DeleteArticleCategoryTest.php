<?php

declare(strict_types=1);

use App\Actions\Content\DeleteArticleCategory;
use App\Exceptions\ArticleCategoryCannotBeDeletedException;
use App\Models\Article;
use App\Models\ArticleCategory;
use Database\Seeders\System\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

/*
 * EditArticleCategory's default DeleteAction previously called
 * $record->delete() directly, surfacing articles.article_category_id's
 * foreign key as an uncaught QueryException (1451) instead of a message
 * naming the dependency. DeleteArticleCategory is what EditArticleCategory
 * now routes through instead.
 */

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(PermissionSeeder::class);
});

it('deletes an article category with no articles', function (): void {
    $category = ArticleCategory::factory()->create();

    app(DeleteArticleCategory::class)->handle($category, null);

    expect(ArticleCategory::find($category->getKey()))->toBeNull();
});

it('refuses an article category with an article and writes nothing', function (): void {
    $category = ArticleCategory::factory()->create();
    Article::factory()->create(['article_category_id' => $category->getKey()]);

    expect(fn () => app(DeleteArticleCategory::class)->handle($category, null))
        ->toThrow(ArticleCategoryCannotBeDeletedException::class);

    expect(ArticleCategory::find($category->getKey()))->not->toBeNull();
});

it('denies an actor without delete_article_category', function (): void {
    $category = ArticleCategory::factory()->create();
    $actor = catalogueActor('update_article_category');

    expect(fn () => app(DeleteArticleCategory::class)->handle($category, $actor))
        ->toThrow(AuthorizationException::class);

    expect(ArticleCategory::find($category->getKey()))->not->toBeNull();
});

it('allows an actor holding delete_article_category', function (): void {
    $category = ArticleCategory::factory()->create();
    $actor = catalogueActor('delete_article_category');

    app(DeleteArticleCategory::class)->handle($category, $actor);

    expect(ArticleCategory::find($category->getKey()))->toBeNull();
});
