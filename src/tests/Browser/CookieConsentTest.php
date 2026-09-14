<?php

declare(strict_types=1);

/*
 * The consent banner has to go away when clicked, and the choice has to reach
 * the server: the banner is rendered only while `cookie_consent` is absent,
 * so its absence on the next page load is what proves the cookie was sent and
 * read unencrypted. CookieConsentBannerTest covers the server half alone.
 */

it('hides the banner and remembers the choice', function (string $button, string $value): void {
    $page = visit('/faq')
        ->assertSee('Decline non-essential')
        ->click($button)
        ->assertDontSee('Decline non-essential');

    expect($page->script('document.cookie'))->toContain("cookie_consent={$value}");

    $page->navigate('/about')
        ->assertDontSee('Decline non-essential');
})->with([
    'accept' => ['OK', 'accepted'],
    'decline' => ['Decline non-essential', 'rejected'],
]);
