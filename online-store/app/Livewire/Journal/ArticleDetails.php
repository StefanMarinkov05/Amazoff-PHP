<?php

declare(strict_types=1);

namespace App\Livewire\Journal;

use App\Models\Article;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * @property-read Article $article
 * @property-read Collection<int, Article> $related
 */
class ArticleDetails extends Component
{
    /**
     * Locked because a public property is re-hydrated from the client on
     * every update, and this one selects which article is rendered. Without
     * it, a crafted Livewire request could set it to a draft's id after
     * mount() has already run its visibility check. `#[Locked]` is defence
     * in depth; the load-bearing guard is that `article()` below re-applies
     * `->visible()` on every read, so the entitlement travels with the id
     * rather than being checked once. See `reference/testing/security-testing.md`
     * SEC-001.
     */
    #[Locked]
    public int $articleId;

    public function mount(Article $article): void
    {
        abort_unless(
            Article::query()->visible()->whereKey($article->getKey())->exists(),
            404
        );
        $this->articleId = $article->getKey();
    }

    #[Computed]
    public function article(): Article
    {
        // `->visible()` here, not only in mount(): a bare findOrFail on a
        // client-controlled id renders drafts and embargoed articles. The
        // scope makes a non-public id 404 rather than leak — SEC-001.
        return Article::query()
            ->visible()
            ->with(['author', 'articleCategory', 'tags'])
            ->findOrFail($this->articleId);
    }

    /**
     * Three more from the same category. Falls back to the newest overall so
     * a category holding one article still ends the page with somewhere to go.
     *
     * @return Collection<int, Article>
     */
    #[Computed]
    public function related(): Collection
    {
        $sameCategory = Article::query()
            ->visible()
            ->where('article_category_id', $this->article->article_category_id)
            ->whereKeyNot($this->articleId)
            ->latest('published_at')
            ->limit(3)
            ->get();

        if ($sameCategory->isNotEmpty()) {
            return $sameCategory;
        }

        return Article::query()
            ->visible()
            ->whereKeyNot($this->articleId)
            ->latest('published_at')
            ->limit(3)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.journal.article-details')
            ->title($this->article->title);
    }
}
