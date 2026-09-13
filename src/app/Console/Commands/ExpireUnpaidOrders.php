<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Order\ExpireUnpaidOrders as ExpireUnpaidOrdersAction;
use Illuminate\Console\Command;

final class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Cancel card orders left unpaid past config(orders.unpaid_ttl_minutes), releasing their stock';

    public function handle(ExpireUnpaidOrdersAction $action): int
    {
        $cancelled = $action->handle();

        $this->info("Cancelled {$cancelled} unpaid order(s) and released their stock.");

        return self::SUCCESS;
    }
}
