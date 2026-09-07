<?php

declare(strict_types=1);

use App\Enums\ArticleStatus;
use App\Livewire\Journal\ArticleDetails;
use App\Models\Article;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * SEC-001 (reference/testing/security-testing/sec-001-to-004.md): an unauthenticated visitor could
 * read a draft or an embargoed article by tampering with `articleId` — a
 * client-writable public property — after mount()'s visibility check had
 * already passed against a published article. Two mechanisms fix it, and
 * each has a test here that goes red if that mechanism is removed:
 *
 *   1. `article()` re-applies `->visible()` on every read (the load-bearing
 *      guard: the entitlement travels with the id, not just mount()).
 *   2. `#[Locked]` on `$articleId` refuses the client write outright
 *      (defence in depth).
 *
 * The lock is what makes the exploit's own move — set('articleId', $draft) —
 * throw rather than succeed, so proving the scope needs a component whose id
 * already points at the non-public article (a direct id-bound test), while
 * proving the lock needs the tampering attempt on a live component.
 */

function makePublishedArticle(): Article
{
    /** @var Article $article */
    $article = Article::factory()->create([
        'status' => ArticleStatus::Published,
        'published_at' => now()->subDay(),
    ]);

    return $article;
}

it('renders a published article normally', function (): void {
    $article = makePublishedArticle();

    Livewire::test(ArticleDetails::class, ['article' => $article])
        ->assertOk()
        ->assertSeeText($article->title);
});

it('refuses a draft article at mount', function (): void {
    $draft = Article::factory()->create([
        'status' => ArticleStatus::Draft,
        'published_at' => null,
    ]);

    // mount()'s abort_unless is the first gate; a draft never resolves here.
    Livewire::test(ArticleDetails::class, ['article' => $draft])
        ->assertStatus(404);
});

it('refuses an article scheduled for the future at mount', function (): void {
    $embargoed = Article::factory()->create([
        'status' => ArticleStatus::Scheduled,
        'published_at' => now()->addWeek(),
    ]);

    Livewire::test(ArticleDetails::class, ['article' => $embargoed])
        ->assertStatus(404);
});

it('locks articleId against client tampering', function (): void {
    // The exploit's own move: point a live component (mounted on a published
    // article) at a draft's id. #[Locked] must refuse it. Remove the
    // attribute and this set() succeeds instead of throwing — the test goes
    // red, which is the point.
    $published = makePublishedArticle();
    $draft = Article::factory()->create([
        'status' => ArticleStatus::Draft,
        'published_at' => null,
    ]);

    $component = Livewire::test(ArticleDetails::class, ['article' => $published]);

    expect(fn () => $component->set('articleId', $draft->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('the visible() scope, not only #[Locked], is what fails safe', function (): void {
    // Proves guard (1) independently of the lock. A draft id is placed on the
    // component from mount (bypassing the lock, which only guards client
    // writes), then article() is read directly. With ->visible() in article()
    // this findOrFail misses and throws ModelNotFoundException (a 404);
    // remove ->visible() and it returns the draft, so this test goes red.
    $draft = Article::factory()->create([
        'status' => ArticleStatus::Draft,
        'published_at' => null,
    ]);

    $component = new ArticleDetails;
    $component->articleId = $draft->getKey();

    expect(fn () => $component->article())
        ->toThrow(ModelNotFoundException::class);
});
