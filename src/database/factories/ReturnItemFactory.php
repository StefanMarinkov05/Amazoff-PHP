<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\ReturnItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReturnItem>
 */
class ReturnItemFactory extends Factory
{
    protected $model = ReturnItem::class;

    public function definition(): array
    {
        return [
            'return_id' => OrderReturn::factory(),
            'order_item_id' => OrderItem::factory(),
            'quantity' => 1,
        ];
    }
}
