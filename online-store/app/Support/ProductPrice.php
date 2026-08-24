<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Carbon;

final class ProductPrice
{
    public function __construct(
        public readonly string $current,
        public readonly string $regular,
        public readonly bool $onSale,
        public readonly int $percent,
    ) {}
}
