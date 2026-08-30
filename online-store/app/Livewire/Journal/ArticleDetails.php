<?php

declare(strict_types=1);

namespace App\Livewire\Journal;

use App\Models\Article;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ArticleDetails extends Component
{
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
        return Article::query()->where('id', $this->articleId)
            ->with(['author', 'articleCategory', 'tags'])
            ->findOrFail($this->articleId);
    }

    public function render(): View
    {
        return view('livewire.journal.article-details')
            ->title($this->article->title);
    }
}
