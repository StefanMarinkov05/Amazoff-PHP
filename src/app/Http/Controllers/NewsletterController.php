<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Contact\ConfirmNewsletterSubscription;
use App\Actions\Contact\UnsubscribeFromNewsletter;
use Illuminate\View\View;

/**
 * The landing pages for the links in the newsletter emails (ADR-0019). No
 * form, no state — the token in the URL is the whole input, so a thin
 * controller rendering a result view is the right shape, not a Livewire
 * component (`storefront-pages.md`, "informational pages are plain views").
 *
 * The token is matched on a UNIQUE 64-char column; possessing it is the
 * authorisation. Neither route is rate-limited at the controller — a wrong
 * token simply renders the "link is not valid" view, and there is nothing
 * to enumerate: the tokens are not sequential and reveal nothing.
 */
final class NewsletterController extends Controller
{
    public function confirm(string $token, ConfirmNewsletterSubscription $confirm): View
    {
        $subscriber = $confirm->handle($token);

        return view('pages.newsletter.confirm', [
            'ok' => $subscriber !== null,
        ]);
    }

    public function unsubscribe(string $token, UnsubscribeFromNewsletter $unsubscribe): View
    {
        $subscriber = $unsubscribe->handle($token);

        return view('pages.newsletter.unsubscribed', [
            'ok' => $subscriber !== null,
        ]);
    }
}
