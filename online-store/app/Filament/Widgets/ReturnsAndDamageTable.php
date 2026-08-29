<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Inventory;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Which products are losing the most stock to returns and damage, not just
 * which have the highest raw count — ranked by loss rate (returned + damaged
 * as a share of sold), since a product selling 5 units with 2 returned is a
 * worse sign than one selling 500 with 5 returned, and a raw-count ranking
 * would show the second as the "problem."
 *
 * Reads `inventories` directly (`sold_quantity`, `returned_quantity`,
 * `damaged_quantity` are maintained columns per §20 — the same reasoning
 * `Order::$payment_status`'s docblock gives for not storing a derivable
 * fact twice applies in reverse here: these are the one place this count
 * legitimately lives, kept current by `ReleaseStock`/`RecordDamage`/
 * `RestockReturn`, not recomputed from `order_items` on every read).
 */
class ReturnsAndDamageTable extends TableWidget
{
    protected static ?int $sort = 5;

    protected static ?string $heading = 'Highest return & damage rate';

    protected int|string|array $columnSpan = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Inventory::query()
                    ->join('product_variations', 'product_variations.id', '=', 'inventories.product_variation_id')
                    ->join('products', 'products.id', '=', 'product_variations.product_id')
                    ->where('inventories.sold_quantity', '>', 0)
                    ->select([
                        'inventories.id',
                        'products.name as product_name',
                        'product_variations.sku',
                        'inventories.sold_quantity',
                        'inventories.returned_quantity',
                        'inventories.damaged_quantity',
                    ])
                    ->selectRaw(
                        '((inventories.returned_quantity + inventories.damaged_quantity) / inventories.sold_quantity) * 100 as loss_rate',
                    )
                    ->orderByDesc('loss_rate')
                    ->limit(10),
            )
            ->columns([
                TextColumn::make('product_name')
                    ->label('Product')
                    ->weight('medium'),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->fontFamily('mono')
                    ->color('gray'),
                TextColumn::make('sold_quantity')
                    ->label('Sold')
                    ->numeric(),
                TextColumn::make('returned_quantity')
                    ->label('Returned')
                    ->numeric()
                    ->color('warning'),
                TextColumn::make('damaged_quantity')
                    ->label('Damaged')
                    ->numeric()
                    ->color('danger'),
                TextColumn::make('loss_rate')
                    ->label('Loss rate')
                    ->formatStateUsing(fn (float $state): string => number_format($state, 1).'%')
                    ->weight('bold')
                    ->color(fn (float $state): string => $state > 10.0 ? 'danger' : 'gray'),
            ])
            ->paginated(false);
    }
}
