<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Daily revenue over the last 90 days, from `payments.paid_at`.
 *
 * A longer window than `RevenueOverview`'s 30-day sparkline, deliberately —
 * this is the "is the shop growing" view rather than the "what happened
 * this month" one, and 90 days is enough of the seeded 8-month order
 * history to show a real trend rather than a fortnight of noise.
 */
class RevenueTrendChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Revenue, last 90 days';

    protected int|string|array $columnSpan = 2;

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $from = now()->subDays(89)->startOfDay();
        $to = now()->endOfDay();

        /** @var Collection<string, float|string> $rows */
        $rows = DB::table('payments')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('DATE(paid_at) as day, SUM(amount - refunded_amount) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day');

        $days = collect(range(0, 89))->map(fn (int $i) => now()->subDays(89 - $i)->format('Y-m-d'));

        return [
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'data' => $days->map(fn (string $day): float => round((float) ($rows[$day] ?? 0), 2))->all(),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                ],
            ],
            'labels' => $days->map(fn (string $day) => Carbon::parse($day)->format('M j'))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true],
            ],
            'plugins' => [
                'legend' => ['display' => false],
            ],
        ];
    }
}
