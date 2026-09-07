<?php

declare(strict_types=1);

use App\Actions\Content\PublishArticle;
use App\Enums\ArticleStatus;
use App\Exceptions\ArticleTransitionNotAllowedException;
use App\Models\Article;
use App\Models\User;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;

/*
 * Converts the three-case matrix Phase 4 drove live against a real browser
 * session into a regression test — that pass was evidence, not a test that
 * runs again on the next change. reference/testing/ui-testing/
 * phase-4-admin-panel-clickthrough.md, "The article status menu is enforced,
 * not merely hidden".
 *
 * Case C is the control: if all three refused, the refusals would prove only
 * that the call was broken rather than that the matrix and the policy are
 * each doing their own job.
 */

beforeEach(function (): void {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

it('refuses an illegal transition regardless of who asks (case A)', function (): void {
    /** @var User $editor */
    $editor = User::factory()->create();
    $editor->assignRole('content_editor');

    /** @var Article $article */
    $article = Article::factory()->create(['status' => ArticleStatus::Archived]);

    expect(fn () => app(PublishArticle::class)->handle($article, ArticleStatus::Scheduled, $editor))
        ->toThrow(ArticleTransitionNotAllowedException::class);

    expect($article->fresh()->status)->toBe(ArticleStatus::Archived);
});

it('refuses a legal transition from an actor without publish_article (case B)', function (): void {
    /** @var User $warehouse */
    $warehouse = User::factory()->create();
    $warehouse->assignRole('warehouse_employee');

    /** @var Article $article */
    $article = Article::factory()->create(['status' => ArticleStatus::Archived]);

    expect(fn () => app(PublishArticle::class)->handle($article, ArticleStatus::Published, $warehouse))
        ->toThrow(AuthorizationException::class);

    expect($article->fresh()->status)->toBe(ArticleStatus::Archived);
});

it('accepts a legal transition from an actor with publish_article — the control (case C)', function (): void {
    /** @var User $editor */
    $editor = User::factory()->create();
    $editor->assignRole('content_editor');

    /** @var Article $article */
    $article = Article::factory()->create(['status' => ArticleStatus::Archived, 'published_at' => null]);

    $published = app(PublishArticle::class)->handle($article, ArticleStatus::Published, $editor);

    expect($published->status)->toBe(ArticleStatus::Published)
        ->and($published->published_at)->not->toBeNull();
});
