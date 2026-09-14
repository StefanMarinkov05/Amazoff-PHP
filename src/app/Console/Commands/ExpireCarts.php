<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Cart\ExpireCarts as ExpireCartsAction;
use Illuminate\Console\Command;

final class ExpireCarts extends Command
{
    protected $signature = 'carts:expire';

    protected $description = 'Delete carts past their expires_at, excluding any that already produced an order';

    public function handle(ExpireCartsAction $action): int
    {
        $deleted = $action->handle();

        $this->info("Deleted {$deleted} expired cart(s).");

        return self::SUCCESS;
    }
}
