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
