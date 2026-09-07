<?php

declare(strict_types=1);

namespace App\Actions\Content;

use App\Enums\ArticleStatus;
use App\Exceptions\ArticleTransitionNotAllowedException;
use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Moves an article through §22's lifecycle, checking the matrix before the
 * write. Publishing is a distinct ability from editing (ADR-0004), so this
 * authorizes `publish`, not `update` — a Select on `status` would have
 * checked the wrong one and skipped the matrix entirely.
 *
 * `published_at` is stamped the first time the article reaches Published and
 * never rewritten: it answers "when did readers first see this", which an
 * unpublish and republish does not change. `updated_at` already records the
 * last edit.
 *
 * Authorizes `publish_article`. Locks nothing — one row, one statement.
 * ADR-0004 · ADR-0007
 */
final class PublishArticle
{
    /**
     * @throws ArticleTransitionNotAllowedException
     */
    public function handle(Article $article, ArticleStatus $to, ?User $actor): Article
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('publish', $article);
        }

        if (! $article->status->canTransitionTo($to)) {
            throw ArticleTransitionNotAllowedException::between($article->status, $to);
        }

        $article->update([
            'status' => $to,
            'published_at' => $article->published_at
                ?? ($to === ArticleStatus::Published ? now() : null),
        ]);

        return $article->refresh();
    }
}
