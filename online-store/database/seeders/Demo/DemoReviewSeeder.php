<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\ProductReview\ApproveProductReview;
use App\Actions\ProductReview\CreateProductReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 90 reviews, drawn only from delivered orders' own line items and their own
 * buyers — `CreateProductReview` enforces §24's verified-purchase rule
 * itself (a delivered order, same user, same product), so this seeder's job
 * is picking real eligible (reviewer, product) pairs and supplying rating +
 * body, not re-deriving the eligibility check.
 *
 * Must run after `DemoOrderSeeder`: there is nothing to review before a
 * delivered order exists.
 */
class DemoReviewSeeder extends Seeder
{
    private const TOTAL_REVIEWS = 90;

    /** Rating => count. Sums to TOTAL_REVIEWS. */
    private const RATING_DISTRIBUTION = [
        5 => 34,
        4 => 27,
        3 => 15,
        2 => 9,
        1 => 5,
    ];

    private const APPROVED_COUNT = 62;

    /**
     * @var array<int, list<string>>
     */
    private const BODIES = [
        5 => [
            'Exactly what I needed and arrived faster than expected. Build quality feels solid, not flimsy at all. Would buy again without hesitation.',
            'This exceeded my expectations honestly. I was a bit worried after reading mixed reviews on similar products elsewhere, but this one is genuinely well made.',
            'Perfect fit for what I was looking for. Easy to use straight out of the box, no fuss setup, and it looks even better in person than in the photos.',
            "Been using this daily for a few weeks now and it's holding up great. No complaints at all - does exactly what it says on the box.",
            'Fantastic value for the price. I compared a few options before buying and this one wins hands down. Highly recommend to anyone on the fence.',
            'Second time ordering this exact item as a gift because the first one I bought for myself was so good. Consistent quality both times.',
            'Really impressed with the packaging too - arrived in perfect condition. The product itself works flawlessly so far.',
            'Five stars without a doubt. Customer service was also excellent when I had a quick question about sizing before ordering.',
        ],
        4 => [
            'Good product overall, does what it promises. Only minor gripe is the instructions could be clearer, but not a dealbreaker.',
            'Solid purchase. Took off one star because the color was slightly different from the listing photo, but otherwise happy with it.',
            'Works well for my needs. A bit pricier than I expected but the quality justifies it for the most part.',
            'Pretty good, would recommend. Delivery took a couple days longer than estimated but the product itself is fine.',
            'Does the job nicely. Not perfect but definitely worth the money at this price point.',
            'Happy with this purchase. Assembly was a little fiddly at first but once set up it works great.',
            'Good quality material, feels durable. Wish it came in more size options but otherwise no real complaints.',
        ],
        3 => [
            "It's okay - does what it says but nothing special. Might look elsewhere next time for something a bit more premium.",
            'Average product. Works fine but feels a little overpriced for what you actually get.',
            'Mixed feelings on this one. The core function is fine but a couple of small design choices feel like an afterthought.',
            'Not bad, not amazing. Got the job done but I probably would not go out of my way to buy it again.',
            'Decent but had higher expectations based on the description. It is functional, just not exciting.',
        ],
        2 => [
            'Disappointed with the quality for the price paid. Works but feels cheaper than expected in hand.',
            'Had some issues out of the box - had to troubleshoot a bit before it worked properly. Functional now but not a great first impression.',
            'Not quite what I expected from the listing photos. Usable but I would think twice before recommending it.',
        ],
        1 => [
            'Arrived with a visible defect and the return process took longer than it should have. Would not order again.',
            'Stopped working properly after just over a week of normal use. Very disappointing given the price.',
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        /** @var Collection<int, OrderItem> $eligibleItems */
        $eligibleItems = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('status', 'delivered')->whereNotNull('user_id'))
            ->with(['order:id,user_id,created_at', 'productVariation:id,product_id'])
            ->get()
            ->filter(function (OrderItem $item): bool {
                /** @var Order|null $order */
                $order = $item->order;
                /** @var ProductVariation|null $variation */
                $variation = $item->productVariation;

                return $order !== null && $variation !== null;
            });

        /** @var Collection<int, array{user_id: int, product_id: int, order_created_at: Carbon}> $pairs */
        $pairs = $eligibleItems
            ->map(function (OrderItem $item): array {
                /** @var Order $order */
                $order = $item->order;
                /** @var ProductVariation $variation */
                $variation = $item->productVariation;

                return [
                    'user_id' => $order->user_id,
                    'product_id' => $variation->product_id,
                    'order_created_at' => $order->created_at,
                ];
            })
            ->unique(fn (array $row): string => $row['user_id'].'|'.$row['product_id'])
            ->values();

        if ($pairs->count() < self::TOTAL_REVIEWS) {
            $this->command?->warn(
                'Only '.$pairs->count().' eligible (reviewer, product) pairs from delivered orders — '
                .'requested '.self::TOTAL_REVIEWS.'. Seeding as many as are available.'
            );
        }

        $pool = $pairs->shuffle()->take(self::TOTAL_REVIEWS);

        $plan = $this->buildRatingPlan($pool->count());

        /** @var CreateProductReview $createReview */
        $createReview = app(CreateProductReview::class);
        /** @var ApproveProductReview $approveReview */
        $approveReview = app(ApproveProductReview::class);

        $created = 0;
        $approved = 0;
        $skipped = 0;

        foreach ($pool->values() as $index => $pair) {
            $rating = $plan[$index];
            $body = $this->bodyFor($rating);

            /** @var User $reviewer */
            $reviewer = User::query()->findOrFail($pair['user_id']);
            /** @var Product $product */
            $product = Product::query()->findOrFail($pair['product_id']);

            try {
                $review = $createReview->handle($product, $reviewer, $rating, $body);
            } catch (\Throwable $e) {
                $skipped++;
                $this->command?->warn("Review skipped for user {$pair['user_id']} / product {$pair['product_id']}: ".$e->getMessage());

                continue;
            }

            $created++;

            // Review timestamp: shortly after the order it depends on, never
            // before it — a review predating its own purchase would be a
            // fixture bug the coverage page would have no way to explain.
            $reviewAt = Carbon::parse($pair['order_created_at'])->addDays(random_int(1, 21));
            $review->forceFill(['created_at' => $reviewAt, 'updated_at' => $reviewAt])->saveQuietly();

            if ($created <= self::APPROVED_COUNT) {
                $approveReview->handle($review, null);
                $approved++;
            }
        }

        $this->command?->info("DemoReviewSeeder: created {$created} review(s), {$approved} approved, {$skipped} skipped.");
        $this->reportActual();
    }

    /**
     * A shuffled list of ratings matching RATING_DISTRIBUTION as closely as
     * $count allows — exact when $count === TOTAL_REVIEWS, scaled down
     * proportionally otherwise (the eligible-pairs pool ran short).
     *
     * @return list<int>
     */
    private function buildRatingPlan(int $count): array
    {
        $plan = [];

        foreach (self::RATING_DISTRIBUTION as $rating => $ratingCount) {
            $scaled = $count === self::TOTAL_REVIEWS
                ? $ratingCount
                : (int) round($ratingCount * $count / self::TOTAL_REVIEWS);

            for ($i = 0; $i < $scaled; $i++) {
                $plan[] = $rating;
            }
        }

        while (count($plan) < $count) {
            $plan[] = 5;
        }

        while (count($plan) > $count) {
            array_pop($plan);
        }

        shuffle($plan);

        return $plan;
    }

    private function bodyFor(int $rating): string
    {
        $bodies = self::BODIES[$rating];

        return $bodies[array_rand($bodies)];
    }

    private function reportActual(): void
    {
        $this->command?->info('--- Verifying against the live database ---');
        $this->command?->info('Total reviews: '.ProductReview::count());

        foreach (ProductReview::query()->selectRaw('rating, count(*) c')->groupBy('rating')->orderBy('rating')->pluck('c', 'rating') as $rating => $count) {
            $this->command?->line("  rating={$rating}: {$count}");
        }

        $this->command?->info('Approved: '.ProductReview::where('approved', true)->count());
        $this->command?->info('Pending: '.ProductReview::where('approved', false)->count());
    }
}
