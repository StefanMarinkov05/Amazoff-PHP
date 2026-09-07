<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Article;
use App\Models\User;

/**
 * See BrandPolicy for why these check permissions rather than roles.
 *
 * §22 separates writing from publishing: a draft is an edit, publication is a
 * distinct act with its own permission. An editor can therefore be given
 * update_article without publish_article, which is the arrangement the
 * specification's "drafts, previews, scheduled publication" implies.
 */
class ArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny_article');
    }

    public function view(User $user, Article $article): bool
    {
        return $user->can('view_article');
    }

    public function create(User $user): bool
    {
        return $user->can('create_article');
    }

    public function update(User $user, Article $article): bool
    {
        return $user->can('update_article');
    }

    public function delete(User $user, Article $article): bool
    {
        return $user->can('delete_article');
    }

    /**
     * §22 and §37 criterion 17. Kept separate from update so that publication
     * can be restricted without also making the article read-only.
     *
     * Whether authorship should narrow this — an editor publishing only their
     * own drafts — is undecided. `articles.author_id` exists, so the check
     * would be `$article->author_id === $user->id`; nothing in §3.3 asks for
     * it, and a two-person editorial team would find it obstructive.
     */
    public function publish(User $user, Article $article): bool
    {
        return $user->can('publish_article');
    }
}
