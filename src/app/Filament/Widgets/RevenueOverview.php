<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use DateTimeInterface;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * The dashboard's top row: revenue, order volume, and return rate.
 *
 * Every figure sums straight from the database rather than loading models —
 * the point of an aggregate query is that it never touches N rows in PHP, so
 * there is no N+1 to eager-load around here the way `Order::$payment_status`
 * needs `with('payment')` in a list.
 *
 * Revenue reads `payments.paid_at`, not `payments.created_at` or
 * `orders.created_at` — `paid_at` is the only column that answers "when did
 * this money actually arrive." `amount - refunded_amount` nets a partial
 * refund down in the same query, without a second pass keyed on status.
 */
class RevenueOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $now = now();
        $periodStart = $now->copy()->subDays(30);
        $previousStart = $now->copy()->subDays(60);

        $current = $this->periodTotals($periodStart, $now);
        $previous = $this->periodTotals($previousStart, $periodStart);

        $avgOrderValue = $current['orders'] > 0 ? $current['revenue'] / $current['orders'] : 0.0;
        $returnRate = $this->returnRate();

        return [
            Stat::make('Revenue (30d)', Number::currency($current['revenue']))
                ->description($this->trendDescription($current['revenue'], $previous['revenue']))
                ->descriptionIcon($this->trendIcon($current['revenue'], $previous['revenue']), IconPosition::Before)
                ->color($this->trendColor($current['revenue'], $previous['revenue']))
                ->chart($this->dailyRevenueChart($periodStart, $now)),

            Stat::make('Orders placed (30d)', Number::format($current['orders']))
                ->description($this->trendDescription($current['orders'], $previous['orders']))
                ->descriptionIcon($this->trendIcon($current['orders'], $previous['orders']), IconPosition::Before)
                ->color($this->trendColor($current['orders'], $previous['orders'])),

            Stat::make('Items sold (30d)', Number::format($current['items']))
                ->description($this->trendDescription($current['items'], $previous['items']))
                ->descriptionIcon($this->trendIcon($current['items'], $previous['items']), IconPosition::Before)
                ->color($this->trendColor($current['items'], $previous['items'])),

            Stat::make('Avg. order value (30d)', Number::currency($avgOrderValue))
                ->description('Paid orders only'),

            Stat::make('Return rate', Number::percentage($returnRate, precision: 1))
                ->description('Returned units ÷ sold units, all time')
                ->color($returnRate > 5.0 ? 'danger' : 'success'),
        ];
    }

    /**
     * @return array{revenue: float, orders: int, items: int}
     */
    private function periodTotals(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $revenueRow = DB::table('payments')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(amount - refunded_amount), 0) as revenue, COUNT(*) as orders')
            ->first();

        $itemsRow = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->whereNotNull('payments.paid_at')
            ->whereBetween('payments.paid_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as items')
            ->first();

        return [
            'revenue' => (float) ($revenueRow->revenue ?? 0),
            'orders' => (int) ($revenueRow->orders ?? 0),
            'items' => (int) ($itemsRow->items ?? 0),
        ];
    }

    private function returnRate(): float
    {
        $row = DB::table('inventories')
            ->selectRaw('COALESCE(SUM(returned_quantity), 0) as returned, COALESCE(SUM(sold_quantity), 0) as sold')
            ->first();

        $sold = (int) ($row->sold ?? 0);

        if ($sold === 0) {
            return 0.0;
        }

        return round(((int) ($row->returned ?? 0) / $sold) * 100, 1);
    }

    /**
     * @return array<int>
     */
    private function dailyRevenueChart(DateTimeInterface $from, DateTimeInterface $to): array
    {
        /** @var Collection<string, float|string> $rows */
        $rows = DB::table('payments')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('DATE(paid_at) as day, SUM(amount - refunded_amount) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day');

        // A 30-point sparkline against 30 real days, zero-filled where no
        // payment landed — a gap in the series would otherwise read as
        // "the chart broke" rather than "no sales that day."
        $days = collect(range(0, 29))->map(fn (int $i) => now()->subDays(29 - $i)->format('Y-m-d'));

        return $days->map(fn (string $day): int => (int) round((float) ($rows[$day] ?? 0)))->all();
    }

    private function trendDescription(float|int $current, float|int $previous): string
    {
        if ((float) $previous === 0.0) {
            return (float) $current > 0.0 ? 'New this period' : 'No change';
        }

        $change = (($current - $previous) / $previous) * 100;

        return Number::percentage(abs($change), precision: 1).' vs. previous 30 days';
    }

    private function trendIcon(float|int $current, float|int $previous): string
    {
        return (float) $current >= (float) $previous ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';
    }

    private function trendColor(float|int $current, float|int $previous): string
    {
        return (float) $current >= (float) $previous ? 'success' : 'danger';
    }
}
