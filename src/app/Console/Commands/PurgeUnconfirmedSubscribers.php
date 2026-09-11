<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Contact\PurgeUnconfirmedSubscribers as PurgeAction;
use Illuminate\Console\Command;

final class PurgeUnconfirmedSubscribers extends Command
{
    protected $signature = 'newsletter:purge-unconfirmed';

    protected $description = 'Delete newsletter rows still Pending after the double-opt-in grace period';

    public function handle(PurgeAction $action): int
    {
        $deleted = $action->handle();

        $this->info("Deleted {$deleted} unconfirmed newsletter subscriber(s).");

        return self::SUCCESS;
    }
}
