<?php

declare(strict_types=1);

namespace App\Actions\Content;

use App\Exceptions\ArticleCategoryCannotBeDeletedException;
use App\Models\ArticleCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deletes an article category, refusing while it still has articles.
 *
 * `articles.article_category_id`'s foreign key already blocks this at the
 * database as error 1451, a raw `QueryException`. This Action turns that
 * into a message an administrator can act on, thrown from inside a lock
 * rather than left to the database: an article assigned to this category
 * in the same instant is either already visible to the count or is itself
 * blocked waiting on the lock — see `explanation/concurrency-and-locking.md`.
 *
 * Authorizes `delete_article_category`. Locks `article_categories`.
 */
final class DeleteArticleCategory
{
    /**
     * @throws ArticleCategoryCannotBeDeletedException
     */
    public function handle(ArticleCategory $category, ?User $actor): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $category);
        }

        DB::transaction(function () use ($category): void {
            /** @var ArticleCategory $locked */
            $locked = ArticleCategory::query()->lockForUpdate()->findOrFail($category->getKey());

            $articles = $locked->articles()->count();

            if ($articles > 0) {
                throw ArticleCategoryCannotBeDeletedException::hasArticles($locked, $articles);
            }

            $locked->delete();
        });
    }
}
