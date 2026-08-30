<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * Star distribution across every review, approved or not — a moderator
 * deciding what to do with the pending queue benefits from seeing whether
 * the backlog skews negative, which an approved-only count would hide.
 */
class RatingDistributionChart extends ChartWidget
{
    protected static ?int $sort = 7;

    protected ?string $heading = 'Rating distribution';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $counts = DB::table('product_reviews')
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $ratings = [1, 2, 3, 4, 5];

        return [
            'datasets' => [
                [
                    'label' => 'Reviews',
                    'data' => array_map(fn (int $r): int => (int) ($counts[$r] ?? 0), $ratings),
                    'backgroundColor' => ['#ef4444', '#f59e0b', '#eab308', '#84cc16', '#10b981'],
                ],
            ],
            'labels' => array_map(fn (int $r): string => "{$r} ★", $ratings),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['stepSize' => 1]],
            ],
            'plugins' => [
                'legend' => ['display' => false],
            ],
        ];
    }
}
