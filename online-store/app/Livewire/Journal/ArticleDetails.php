<?php

declare(strict_types=1);

namespace App\Livewire\Journal;

use App\Models\Article;
use App\Models\User;
use App\Enums\ArticleStatus;
use Illuminate\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class ArticleDetails extends Component
{
    #[Url]
    public ?int $categoryId = null;
    #[Url]
    public ?string $tag = null;
    #[Url]
    public int $articleId;

    public function mount(Article $article): void
    {
        abort_unless(
            $article->status === ArticleStatus::Published
                && $article->published_at?->isPast(),
            404
        );
        $this->articleId = $article->id;
    }

    #[Computed]
    public function article(): Article
    {
        return Article::query()->where('id', $this->articleId)
            ->with(['author', 'articleCategory'])
            ->firstOrFail($this->articleId);
    }

    #[Computed]
    public function category()
    {
        return
    }

    public function render(): View
    {
        return view('livewire.journal.article-details')
            ->title($this->article->name);
    }
}
