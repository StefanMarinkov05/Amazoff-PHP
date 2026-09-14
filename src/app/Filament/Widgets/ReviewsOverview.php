<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * Review volume and moderation backlog — `approved` is the queue
 * `content_editor` actually works from (`ProductReviewResource` is
 * moderation-only: approve/unapprove, no create), so the pending count here
 * is the number that matters to that role, not a vanity metric.
 */
class ReviewsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 6;

    protected function getStats(): array
    {
        $row = DB::table('product_reviews')
            ->selectRaw('
                COUNT(*) as total,
                SUM(approved = 1) as approved,
                SUM(approved = 0) as pending,
                AVG(CASE WHEN approved = 1 THEN rating END) as avg_rating
            ')
            ->first();

        $recent = (int) DB::table('product_reviews')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $avgRating = (float) ($row->avg_rating ?? 0);

        return [
            Stat::make('Average rating', $avgRating > 0 ? number_format($avgRating, 1).' / 5' : '—')
                ->description('Approved reviews only'),

            Stat::make('Pending approval', Number::format((int) ($row->pending ?? 0)))
                ->description('Awaiting moderation')
                ->color((int) ($row->pending ?? 0) > 0 ? 'warning' : 'success'),

            Stat::make('Approved reviews', Number::format((int) ($row->approved ?? 0)))
                ->description(Number::format((int) ($row->total ?? 0)).' total'),

            Stat::make('New reviews (30d)', Number::format($recent)),
        ];
    }
}
