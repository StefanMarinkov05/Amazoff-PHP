<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * Every current order, grouped by status — the pipeline as it stands right
 * now rather than a time-boxed slice, since "where are orders stuck" is a
 * present-tense question. Colours mirror `OrderStatus::getColor()` so this
 * chart never disagrees with the status badge shown everywhere else in the
 * panel; Filament's semantic colour names are translated to hex by hand
 * since Chart.js needs real colour values, not Filament's named palette.
 */
class OrdersByStatusChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Orders by status';

    protected int|string|array $columnSpan = 2;

    /** @var array<string, string> */
    private const COLOR_HEX = [
        'gray' => '#6b7280',
        'warning' => '#f59e0b',
        'success' => '#10b981',
        'info' => '#3b82f6',
        'danger' => '#ef4444',
    ];

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $counts = DB::table('orders')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statuses = OrderStatus::cases();

        return [
            'datasets' => [
                [
                    'label' => 'Orders',
                    'data' => array_map(fn (OrderStatus $status): int => (int) ($counts[$status->value] ?? 0), $statuses),
                    'backgroundColor' => array_map(
                        fn (OrderStatus $status): string => self::COLOR_HEX[$status->getColor()] ?? '#6b7280',
                        $statuses,
                    ),
                ],
            ],
            'labels' => array_map(fn (OrderStatus $status): string => $status->getLabel(), $statuses),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'scales' => [
                'x' => ['beginAtZero' => true, 'ticks' => ['stepSize' => 1]],
            ],
            'plugins' => [
                'legend' => ['display' => false],
            ],
        ];
    }
}
