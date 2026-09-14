<?php

declare(strict_types=1);

/*
 * The privacy notice and T&Cs are full structured drafts (ADR-0019). This
 * test pins the shape — the required content is present and the "not legal
 * advice yet" banner is shown — rather than the exact wording, which counsel
 * will change.
 */

it('serves the privacy notice with the draft banner and the required sections', function (): void {
    $this->get('/privacy')
        ->assertOk()
        ->assertSee('Draft — not legal advice yet.')
        // Per-purpose legal bases (GDPR Arts. 6, 13-14).
        ->assertSee('Legal basis')
        ->assertSee('Art. 6(1)(b)')
        ->assertSee('Art. 6(1)(a)')
        // Retention.
        ->assertSee('How long we keep it')
        // Rights + the self-service routes.
        ->assertSee('Your rights')
        ->assertSeeHtml('href="/account/data"')
        ->assertSeeHtml('href="/account/delete"')
        // Processors incl. the US transfer.
        ->assertSee('Stripe')
        ->assertSee('Standard Contractual Clauses')
        // Supervisory authority (Art. 77).
        ->assertSee('Комисия за защита на личните данни');
});

it('serves the terms with the draft banner and the withdrawal right', function (): void {
    $this->get('/terms')
        ->assertOk()
        ->assertSee('Draft — not legal advice yet.')
        ->assertSee('right of withdrawal', false)
        ->assertSee('14 days')
        ->assertSee('Order with obligation to pay')
        ->assertSeeHtml('href="/returns/withdrawal-form"')
        // Consumer dispute resolution.
        ->assertSee('Online Dispute Resolution')
        // Points at the privacy notice for data handling.
        ->assertSeeHtml('href="/privacy"');
});

it('still serves the cookie policy', function (): void {
    $this->get('/cookies')->assertOk()->assertSee('Cookie policy');
});
