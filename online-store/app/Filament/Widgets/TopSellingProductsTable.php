<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\DB;

/**
 * Best sellers by units, all time, read from `order_items` rather than
 * `products` — the snapshot columns (`product_name`, `product_sku`) survive
 * a product being deleted later, same reason `CreateOrder` writes them in
 * the first place. A product deleted after selling well would silently
 * vanish from this list if it joined `products` instead.
 *
 * Excludes orders that never became real sales: `New` and `AwaitingPayment`
 * have not been paid for yet, `Cancelled` was refused. Everything from
 * `Paid` onward — including `Returned`/`Refunded`, which still happened —
 * counts, matching what `inventories.sold_quantity` itself counts.
 */
class TopSellingProductsTable extends TableWidget
{
    protected static ?int $sort = 4;

    protected static ?string $heading = 'Top-selling products (all time)';

    protected int|string|array $columnSpan = 2;

    public function table(Table $table): Table
    {
        $excluded = [
            OrderStatus::New->value,
            OrderStatus::AwaitingPayment->value,
            OrderStatus::Cancelled->value,
        ];

        return $table
            ->query(
                OrderItem::query()
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereNotIn('orders.status', $excluded)
                    ->select([
                        DB::raw('MIN(order_items.id) as id'),
                        'order_items.product_sku',
                        'order_items.product_name',
                    ])
                    ->selectRaw('SUM(order_items.quantity) as total_quantity')
                    ->selectRaw('SUM(order_items.line_total) as total_revenue')
                    ->groupBy('order_items.product_sku', 'order_items.product_name')
                    ->orderByDesc('total_quantity')
                    ->limit(10),
            )
            // Filament appends `order by <primary key>` as a stable-sort
            // tiebreaker by default. That column isn't in this query's GROUP
            // BY, and MySQL's only_full_group_by mode (the default; ADR-0005
            // is explicit this suite runs against real MySQL specifically to
            // keep such constraints live) rejects it outright. The query is
            // already fully ordered by total_quantity, so the tiebreaker adds
            // nothing here — it only breaks the query.
            ->defaultKeySort(false)
            ->columns([
                TextColumn::make('product_name')
                    ->label('Product')
                    ->weight('medium'),
                TextColumn::make('product_sku')
                    ->label('SKU')
                    ->fontFamily('mono')
                    ->color('gray'),
                TextColumn::make('total_quantity')
                    ->label('Units sold')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_revenue')
                    ->label('Revenue')
                    ->money(),
            ])
            ->paginated(false);
    }
}
