<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Gdpr\PurgeAnonymisedOrders as PurgeAction;
use Illuminate\Console\Command;

final class PurgeAnonymisedOrders extends Command
{
    protected $signature = 'orders:purge-anonymised';

    protected $description = 'Delete GDPR-anonymised orders past config(gdpr.order_retention_years)';

    public function handle(PurgeAction $action): int
    {
        $deleted = $action->handle();

        if ($deleted === null) {
            $this->warn('Retention purge is disabled (config gdpr.order_retention_years is null).');

            return self::SUCCESS;
        }

        $this->info("Purged {$deleted} anonymised order(s) past their retention period.");

        return self::SUCCESS;
    }
}
