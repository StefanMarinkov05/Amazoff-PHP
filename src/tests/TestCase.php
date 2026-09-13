<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    /**
     * A route-level test (a plain `->get('/some-route')` through the full
     * layout, not `Livewire::test(Component::class)`) hits `@vite(...)` in
     * `components/layouts/app.blade.php`. Locally the `vite` Docker
     * service always leaves `public/hot` behind, so `Vite::__invoke()`
     * never reaches the manifest path; CI has neither `public/hot` nor a
     * built manifest — `use-ci.md`'s `test` job deliberately has no
     * npm/build step — and throws `ViteManifestNotFoundException`.
     *
     * This used to be a `beforeEach` duplicated per test file
     * (`CheckoutTest`, `AuthSessionInvalidationTest`), which worked only
     * as long as every route-level test file wrote its own fake or
     * happened to run after one that did — the same file-scoped fake
     * written once, read by a later file in the same process. Two files
     * (`ExampleTest`, `RequestPasswordResetTest`) hit a real route without
     * one and relied on shard luck instead: Pest 5's time-balanced
     * sharding (ADR-0018) reassigns which classes land in which shard on
     * every `--update-shards` refresh, so nothing guaranteed one of the
     * faking files would run first in the same shard. Confirmed live: a
     * shard refresh alone — no test or app code touched — sent both to a
     * shard with nothing ahead of them, and CI went red on a change that
     * never touched either file.
     *
     * Fixed here, in `setUp()`, rather than as a `beforeEach()` in
     * `tests/Pest.php` — tried first and confirmed *not* to work: a bare
     * `beforeEach()` written in `Pest.php` is registered under that
     * file's own name in Pest's `BeforeEachRepository`, which only a test
     * physically written in `Pest.php` would ever resolve. `setUp()` on
     * this class is the one already-proven way to reach every Feature
     * test — `pest()->extend(TestCase::class)->in('Feature')` in
     * `tests/Pest.php` is what LazilyRefreshDatabase rides to reach the
     * same tests.
     *
     * `docs/how-to/troubleshooting/auth-and-sessions.md`, "A Feature test
     * passes locally and fails in CI with ViteManifestNotFoundException"
     * has the fuller incident history.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $buildPath = public_path('build');

        if (File::exists($buildPath.'/manifest.json')) {
            return;
        }

        File::ensureDirectoryExists($buildPath);

        // 'file' and 'src' are dereferenced unconditionally by
        // Vite::__invoke() for every entrypoint passed to @vite();
        // everything else ('css', imports) is read with `?? []` and can
        // be omitted, since nothing in the affected tests asserts on the
        // rendered HTML — only on status codes and redirect targets.
        File::put($buildPath.'/manifest.json', json_encode([
            'resources/css/app.css' => ['file' => 'assets/app.css', 'src' => 'resources/css/app.css'],
            'resources/js/app.js' => ['file' => 'assets/app.js', 'src' => 'resources/js/app.js'],
        ]));

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($buildPath));
    }
}
