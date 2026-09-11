<?php

declare(strict_types=1);

namespace App\Livewire\Journal;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Tag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read LengthAwarePaginator<int, Article> $articles
 * @property-read Collection<int, ArticleCategory> $categories
 * @property-read Collection<int, Tag> $tags
 */
class ArticleList extends Component
{
    /**
     * Deliberately `mixed`, not `?int` — the same incident as
     * `ProductList::$brandId`: a number too large for PHP to represent as an
     * `int` (`?categoryId=99999999999999999999999999999999`) decodes to a
     * `float` during Livewire's `#[Url]` hydration, which runs before any of
     * this class's own code — `?int` refuses the assignment right there, an
     * unhandled 500 from a crafted URL, confirmed live before this fix.
     * `safeCategoryId()` normalises it wherever it is read.
     */
    #[Url]
    public mixed $categoryId = null;

    #[Url]
    public ?string $tag = null;

    use WithPagination;

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    /** @return LengthAwarePaginator<int, Article> */
    #[Computed]
    public function articles(): LengthAwarePaginator
    {
        $query = Article::query()
            ->with(['author', 'articleCategory'])->visible();
        $this->applyFilters($query);

        return $query->latest('published_at')->paginate(12);
    }

    /** @return Collection<int, ArticleCategory> */
    #[Computed]
    public function categories(): Collection
    {
        return ArticleCategory::query()
            ->whereHas('articles', function (Builder $query): void {
                /** @var Builder<Article> $query */
                $query->visible();
            })
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Tag> */
    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()
            ->whereHas('articles', function (Builder $query): void {
                /** @var Builder<Article> $query */
                $query->visible();
            })
            ->orderBy('name')
            ->get();
    }

    /** @param Builder<Article> $query */
    private function applyFilters(Builder $query, ?string $skip = null): void
    {
        if ($skip !== 'categoryId' && $this->safeCategoryId() !== null) {
            $query->where('article_category_id', $this->safeCategoryId());
        }

        if ($skip !== 'tag' && $this->tag !== null) {
            $query->whereHas('tags', function (Builder $t): Builder {
                /** @var Builder<Tag> $t */
                return $t->where('slug', $this->tag);
            });
        }
    }

    /**
     * A clean int-shaped value normalises; anything else (non-numeric, a
     * decimal, a number PHP represents as a float once past its int range)
     * becomes null, same as a well-formed id for a category that does not
     * exist — both fall through to "no category filter applied" rather than
     * erroring.
     */
    private function safeCategoryId(): ?int
    {
        return is_numeric($this->categoryId) && (int) $this->categoryId == $this->categoryId
            ? (int) $this->categoryId
            : null;
    }

    public function render(): View
    {
        return view('livewire.journal.article-list', [
            'articles' => $this->articles,
        ]);
    }
}
