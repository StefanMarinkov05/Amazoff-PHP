<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\ArticleCategory;
use RuntimeException;

/**
 * An article category was refused deletion because it still has articles.
 *
 * `articles.article_category_id` is a nullable `constrained()` foreign key
 * with no cascade, so the database already refuses to delete a category
 * any article still points at — as error 1451, a raw `QueryException`. This
 * exception is what turns that into a message an administrator can act on,
 * thrown from inside a lock rather than left to the database:
 * `DeleteArticleCategory` re-reads the live article count under
 * `lockForUpdate()` before deciding, so an article assigned to this
 * category in the same instant cannot slip past a stale count.
 */
class ArticleCategoryCannotBeDeletedException extends RuntimeException
{
    public function __construct(string $message, public readonly ArticleCategory $category)
    {
        parent::__construct($message);
    }

    public static function hasArticles(ArticleCategory $category, int $articles): self
    {
        return new self(sprintf(
            'Article category %s has %d article(s) and cannot be deleted.',
            $category->name,
            $articles,
        ), $category);
    }
}
