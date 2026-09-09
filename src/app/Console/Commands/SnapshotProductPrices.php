<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Catalogue\RecordProductPrices;
use Illuminate\Console\Command;

/**
 * Records today's effective selling price for every product, for the Omnibus
 * prior-price display (ADR-0021). Scheduled daily in `routes/console.php`.
 */
final class SnapshotProductPrices extends Command
{
    protected $signature = 'products:snapshot-prices';

    protected $description = 'Record every product\'s effective price for the Omnibus 30-day prior-price display';

    public function handle(RecordProductPrices $action): int
    {
        $written = $action->handle();

        $this->info("Recorded {$written} product price observation(s).");

        return self::SUCCESS;
    }
}
