<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Housekeeping, not time-sensitive — daily is plenty until a TTL policy
// gives carts an actual expires_at to act on. See ExpireCarts's docblock.
// withoutOverlapping() even though today's run is always fast: a stalled
// run should skip the next tick rather than queue a second one against the
// same table.
Schedule::command('carts:expire')->daily()->withoutOverlapping();

// GDPR Art. 5(1)(e) — delete anonymised orders past their accounting
// retention period (ADR-0019). Weekly is ample: the window is measured in
// years, so a few days' lag between expiry and deletion is immaterial, and
// a daily run would mostly do nothing. Disabled unless
// GDPR_ORDER_RETENTION_YEARS is set — the Action returns null and the
// command says so.
Schedule::command('orders:purge-anonymised')->weekly()->withoutOverlapping();

// ePrivacy Art. 13 — an address that was submitted but never confirmed the
// double opt-in is held without consent; drop it after the grace period
// (ADR-0019). Daily is fine: the set is small and the grace window is 30
// days, so timing is not tight.
Schedule::command('newsletter:purge-unconfirmed')->daily()->withoutOverlapping();

// Omnibus Directive (EU) 2019/2161 — record every product's effective price
// once a day so the storefront can show the lowest price in the 30 days
// before a reduction (ADR-0021). Daily is the whole point: it captures a
// scheduled discount window opening or closing without an admin edit, and
// makes "the lowest price applied during the 30 days" a direct query.
Schedule::command('products:snapshot-prices')->daily()->withoutOverlapping();
