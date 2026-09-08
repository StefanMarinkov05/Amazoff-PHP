<?php

declare(strict_types=1);

/*
 * Provisional smoke spec — one page, to prove the browser harness itself
 * works end to end (Chromium launches, the in-process app server answers,
 * assets resolve, the truncate/reseed beforeEach leaves a usable database).
 * The real multi-page smoke matrix replaces this once the harness is trusted.
 */

it('loads the home page in a real browser', function (): void {
    $page = visit('/');

    $page->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
