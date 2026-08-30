<?php

declare(strict_types=1);

namespace App\Livewire\Journal;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Tag;
use Illuminate\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ArticleList extends Component
{
    #[Url]
    public ?int $categoryId = null;
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

    #[Computed]
    public function categories(): Collection
    {
        return ArticleCategory::query()
            ->whereHas('articles', fn (Builder $q) => $q->visible())
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()
            ->whereHas('articles', fn (Builder $q) => $q->visible())
            ->orderBy('name')
            ->get();
    }

    private function applyFilters(Builder $query, ?string $skip = null): void
    {
        if ($skip !== 'categoryId' && $this->categoryId !== null) {
            $query->where('article_category_id', $this->categoryId);
        }

        if ($skip !== 'tag' && $this->tag !== null) {
            $query->whereHas('tags', fn (Builder $t) => $t->where('slug', $this->tag));
        }
    }

    public function render(): View
    {
        return view('livewire.journal.article-list', [
            'articles' => $this->articles,
        ]);
    }

}
