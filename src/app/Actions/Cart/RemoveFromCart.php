<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Models\CartItem;

final class RemoveFromCart
{
    public function handle(CartItem $item): void
    {
        $item->delete();
    }
}
