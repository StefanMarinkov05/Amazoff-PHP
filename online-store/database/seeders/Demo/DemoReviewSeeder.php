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
use RuntimeException;

/**
 * 115 reviews, drawn only from delivered orders' own line items and their own
 * buyers — `CreateProductReview` enforces §24's verified-purchase rule
 * itself (a delivered order, same user, same product), so this seeder's job
 * is picking real eligible (reviewer, product) pairs and supplying rating +
 * body, not re-deriving the eligibility check.
 *
 * Must run after `DemoOrderSeeder`: there is nothing to review before a
 * delivered order exists.
 *
 * Review body text lives in `database/fixtures/reference/review-bodies.json`
 * rather than in a const here — it is content, not logic, so a wording
 * change is a data edit and the pool can grow without this class changing.
 */
class DemoReviewSeeder extends Seeder
{
    /**
     * The **upper bound**, not a fixed count. `reviewCount()` decides the
     * real figure per run.
     *
     * There is a hard ceiling below this, and it is not arbitrary:
     * `CreateProductReview` enforces §24's verified-purchase rule (one
     * review per reviewer per product, and only from a delivered order they
     * actually placed), so the pool is the count of *unique* (reviewer,
     * product) pairs across delivered orders. That ceiling **moves a lot
     * between runs** — 137, 123, and 107 on three consecutive seeds —
     * because which orders reach `Delivered`, and how many distinct products
     * they carry, is shuffled.
     *
     * A fixed constant is therefore wrong on nearly every run: set high it
     * warns and under-delivers, set low it wastes an eligible pool. So this
     * is a cap, and the count actually seeded is derived from the live pool.
     */
    private const TOTAL_REVIEWS = 130;

    /**
     * What share of the eligible pool to review, when the pool is smaller
     * than `TOTAL_REVIEWS`.
     *
     * Not 100%: a demo where every delivered line item has a review is as
     * unrealistic as one where none does, and leaving a margin means the
     * "write a review" path on `/account/orders` still has something to
     * point at that has not already been reviewed.
     */
    private const POOL_UTILISATION = 0.85;

    /**
     * Rating => relative weight, not an absolute count. `buildRatingPlan()`
     * scales these to whatever `reviewCount()` resolves to, so the shape
     * holds at any pool size.
     *
     * Shaped like a real catalogue's ratings rather than flat — heavily
     * weighted to 4 and 5, with a real tail. A uniform spread would make
     * every product's average land near 3, which is both unrealistic and
     * useless for demonstrating a sort-by-rating or a star display.
     */
    private const RATING_DISTRIBUTION = [
        5 => 38,
        4 => 30,
        3 => 17,
        2 => 10,
        1 => 5,
    ];

    /**
     * How many are approved. The remainder stay pending, which is the point:
     * the moderation queue in the panel has to have something in it, and the
     * approved set deliberately includes low ratings rather than only the
     * flattering ones.
     */
    /**
     * What share of the seeded reviews are approved. The remainder stay
     * pending, which is the point: the moderation queue in the panel has to
     * have something in it, and the approved set deliberately includes low
     * ratings rather than only the flattering ones.
     *
     * A share rather than a count, for the same reason the total is.
     */
    private const APPROVED_SHARE = 0.7;

    /**
     * Lazily-loaded review bodies, keyed by rating. See `bodies()`.
     *
     * @var array<int, list<string>>|null
     */
    private ?array $bodies = null;

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

        $target = $this->reviewCount($pairs->count());

        $this->command?->info(
            "Eligible (reviewer, product) pairs: {$pairs->count()}; seeding {$target} review(s)."
        );

        $pool = $pairs->shuffle()->take($target);

        $plan = $this->buildRatingPlan($pool->count());

        /** @var CreateProductReview $createReview */
        $createReview = app(CreateProductReview::class);
        /** @var ApproveProductReview $approveReview */
        $approveReview = app(ApproveProductReview::class);

        $approvalTarget = (int) round($pool->count() * self::APPROVED_SHARE);

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

            if ($created <= $approvalTarget) {
                $approveReview->handle($review, null);
                $approved++;
            }
        }

        $this->command?->info("DemoReviewSeeder: created {$created} review(s), {$approved} approved, {$skipped} skipped.");
        $this->reportActual();
    }

    /**
     * How many reviews to actually seed, given the eligible pool this run
     * produced.
     *
     * `TOTAL_REVIEWS` is a cap rather than a target because the pool moves
     * substantially between runs (see that constant's own note). Taking a
     * share of whatever is available means the set scales with the data
     * instead of warning about it, and stays dense at any order count.
     */
    private function reviewCount(int $available): int
    {
        return min(
            self::TOTAL_REVIEWS,
            (int) floor($available * self::POOL_UTILISATION),
        );
    }

    /**
     * A shuffled list of ratings matching RATING_DISTRIBUTION's *shape* as
     * closely as $count allows. The constants are relative weights, not
     * counts, so this scales them to $count in every case.
     *
     * @return list<int>
     */
    private function buildRatingPlan(int $count): array
    {
        $plan = [];

        $weightSum = array_sum(self::RATING_DISTRIBUTION);

        foreach (self::RATING_DISTRIBUTION as $rating => $weight) {
            $scaled = (int) round($weight * $count / $weightSum);

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

    /**
     * Review bodies, keyed by rating, read once from
     * `database/fixtures/reference/review-bodies.json`.
     *
     * Content rather than logic, so it lives as data — same reasoning as
     * `reference/catalogue.json`. Loaded lazily and cached on the instance:
     * `bodyFor()` is called once per review, and re-reading the file 90
     * times would be pointless I/O.
     *
     * @return array<int, list<string>>
     */
    private function bodies(): array
    {
        if ($this->bodies !== null) {
            return $this->bodies;
        }

        $path = database_path('fixtures/reference/review-bodies.json');

        if (! is_file($path)) {
            throw new RuntimeException("Review bodies fixture missing at [{$path}].");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['bodies']) || ! is_array($decoded['bodies'])) {
            throw new RuntimeException("Review bodies fixture at [{$path}] has no 'bodies' object.");
        }

        $bodies = [];

        // Every rating the distribution can produce must be present and
        // non-empty. Checked here rather than at the point of use: a missing
        // key would otherwise surface as a blank review body written to the
        // database, which looks like a seeding success.
        foreach (array_keys(self::RATING_DISTRIBUTION) as $rating) {
            $set = $decoded['bodies'][(string) $rating] ?? null;

            if (! is_array($set) || $set === []) {
                throw new RuntimeException("Review bodies fixture has no entries for rating [{$rating}].");
            }

            $bodies[$rating] = array_values(array_map(strval(...), $set));
        }

        return $this->bodies = $bodies;
    }

    private function bodyFor(int $rating): string
    {
        $bodies = $this->bodies()[$rating];

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
