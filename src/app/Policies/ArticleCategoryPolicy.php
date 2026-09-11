<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ArticleCategory;
use App\Models\User;

/** See BrandPolicy for why these check permissions rather than roles. */
class ArticleCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_article_category');
    }

    public function view(User $user, ArticleCategory $articleCategory): bool
    {
        return $user->can('view_article_category');
    }

    public function create(User $user): bool
    {
        return $user->can('create_article_category');
    }

    public function update(User $user, ArticleCategory $articleCategory): bool
    {
        return $user->can('update_article_category');
    }

    public function delete(User $user, ArticleCategory $articleCategory): bool
    {
        return $user->can('delete_article_category');
    }
}
