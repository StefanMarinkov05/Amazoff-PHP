<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Cart\AddToCart;
use App\Actions\Cart\MergeGuestCart;
use App\Actions\Catalogue\DeleteProductCategory;
use App\Actions\Catalogue\ForceDeleteProductVariation;
use App\Actions\Catalogue\RemoveProductImage;
use App\Actions\Catalogue\RemoveProductVariation;
use App\Actions\Catalogue\SetMainProductImage;
use App\Actions\Catalogue\SetVariationImages;
use App\Actions\Catalogue\UpdateProduct;
use App\Actions\Coupon\RedeemCoupon;
use App\Actions\Inventory\ReleaseStock;
use App\Actions\Inventory\ReserveStock;
use App\Actions\Order\CreateOrder;
use App\Actions\Order\TransitionOrderStatus;
use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductVariation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One side of a two-process race, for `tests/Concurrency`.
 *
 * Every race test needs the same four things — boot the framework, warm the
 * connection, wait for a shared instant, then call one Action and report
 * `OK` or `FAILED:<exception class>`. Before this command each test wrote
 * that as a heredoc, `file_put_contents`'d it into the app root, spawned
 * `php <temp>.php`, and unlinked it in a `finally`. Twelve near-identical
 * copies, and a crash before the `finally` left the temp file behind.
 *
 * Why a command rather than a committed script: `artisan` already bootstraps
 * the framework, so there is no hand-rolled `require bootstrap/app.php` to
 * drift, and the dispatch below is type-checked by Larastan like any other
 * code. The `DB_*` environment still has to be passed by the spawning test
 * either way — a child process never inherits `phpunit.xml`'s connection, so
 * without it a worker silently races against the *dev* database.
 *
 * `--ready-file`/`--peer-file` add the second rendezvous two tests need on
 * top of the wall-clock barrier: where the two sides run different Actions
 * of different internal length, boot jitter alone decides the winner and the
 * race proves nothing. See `AddToCartVsMergeGuestCartConcurrencyTest`.
 *
 * Registered unconditionally — `artisan list` exposes it in production too.
 * It writes only through the same Actions and policies as any other caller,
 * so the exposure is a stray command name, not a bypass.
 */
final class RaceWorker extends Command
{
    protected $signature = 'race:worker
        {action : Which Action to call — see match() below}
        {--id=* : Model ids the chosen action needs, in its documented order}
        {--arg=* : Extra scalar arguments (quantity, status value)}
        {--start-at= : microtime(true) instant every worker releases at}
        {--ready-file= : This worker touches it on arrival}
        {--peer-file= : This worker waits for it before proceeding}';

    protected $description = 'Run one Action as a race participant (tests/Concurrency only)';

    public function handle(): int
    {
        // Warm the connection so the barrier is the last thing that happens
        // before the Action, rather than a TCP handshake being it.
        DB::select('SELECT 1');

        $this->waitForBarrier();
        $this->rendezvousWithPeer();

        try {
            $this->dispatchAction();
            $this->output->write('OK');
        } catch (Throwable $e) {
            $this->output->write('FAILED:'.$e::class);
        }

        return self::SUCCESS;
    }

    /**
     * usleep alone overshoots by milliseconds, so sleep to just before the
     * instant and busy-wait the rest.
     */
    private function waitForBarrier(): void
    {
        $startAt = (float) $this->option('start-at');

        if ($startAt <= 0.0) {
            return;
        }

        if (($remaining = $startAt - microtime(true)) > 0.01) {
            usleep((int) (($remaining - 0.01) * 1_000_000));
        }

        while (microtime(true) < $startAt) {
            // busy-wait to microsecond alignment
        }
    }

    /**
     * Signal arrival, then wait for the other side to signal too, so neither
     * worker enters its Action before both have reached this point.
     */
    private function rendezvousWithPeer(): void
    {
        $readyFile = $this->option('ready-file');
        $peerFile = $this->option('peer-file');

        if (! is_string($readyFile) || ! is_string($peerFile)) {
            return;
        }

        file_put_contents($readyFile, '1');

        $waitUntil = microtime(true) + 2.0;

        while (! file_exists($peerFile) && microtime(true) < $waitUntil) {
            usleep(50);
        }

        @unlink($readyFile);
    }

    private function dispatchAction(): void
    {
        match ($this->argument('action')) {
            'reserve-stock' => app(ReserveStock::class)
                ->handle($this->variation(0), $this->intArg(0), null),
            'release-stock' => app(ReleaseStock::class)
                ->handle($this->variation(0), $this->intArg(0), null),
            'add-to-cart' => app(AddToCart::class)
                ->handle($this->cart(0), $this->variation(1), $this->intArg(0)),
            'merge-guest-cart' => app(MergeGuestCart::class)
                ->handle($this->cart(0), $this->cart(1)),
            'redeem-coupon' => app(RedeemCoupon::class)
                ->handle(Coupon::findOrFail($this->id(0)), Order::findOrFail($this->id(1))),
            'publish-product' => app(UpdateProduct::class)
                ->handle(Product::findOrFail($this->id(0)), ['is_available' => true], null),
            'remove-variation' => app(RemoveProductVariation::class)
                ->handle($this->variation(0), null),
            'force-delete-variation' => app(ForceDeleteProductVariation::class)
                ->handle(ProductVariation::withTrashed()->findOrFail($this->id(0)), null),
            'set-main-image' => app(SetMainProductImage::class)
                ->handle(ProductImage::findOrFail($this->id(0)), null),
            // --id is the variation first, then the gallery in the order it
            // should end up in. One Action owns the whole ordered set, so a
            // race between two of these is a race between two complete
            // galleries, not between an attach and a detach. ADR-0013.
            'set-variation-images' => app(SetVariationImages::class)
                ->handle($this->variation(0), $this->idsFrom(1), null),
            'remove-image' => app(RemoveProductImage::class)
                ->handle(ProductImage::findOrFail($this->id(0)), null),
            'delete-category' => app(DeleteProductCategory::class)
                ->handle(ProductCategory::findOrFail($this->id(0)), null),
            // Plain Eloquent, deliberately: this is what Filament's default
            // create does for a lookup table today, with no Action wrapping
            // it. Racing it against delete-category is the asymmetry that
            // test is about.
            'create-child-category' => ProductCategory::query()->create([
                'parent_id' => $this->id(0),
                'name' => 'Race child',
                'slug' => 'race-child-'.bin2hex(random_bytes(8)),
            ]),
            'transition-order-status' => app(TransitionOrderStatus::class)
                ->handle(
                    Order::findOrFail($this->id(0)),
                    OrderStatus::from($this->stringArg(0)),
                    null,
                ),
            'create-order' => $this->createOrder(),
            default => throw new \InvalidArgumentException(
                'Unknown race action: '.(string) $this->argument('action'),
            ),
        };
    }

    private function createOrder(): void
    {
        $cartId = $this->id(0);

        // Unique per worker: two workers racing the *same* cart must still
        // present distinct customers, or the assertion could not tell a
        // refused duplicate from a collision on the customer snapshot.
        $customer = [
            'email' => 'race-'.$cartId.'-'.bin2hex(random_bytes(4)).'@example.com',
            'phone' => '0000000000',
            'first_name' => 'Race',
            'last_name' => (string) $cartId,
            'payment_method' => PaymentMethod::CashOnDelivery,
        ];

        $address = [
            'delivery_type' => DeliveryType::Address,
            'first_name' => 'Race',
            'last_name' => (string) $cartId,
            'phone' => '0000000000',
            'country' => 'BG',
            'city' => 'Sofia',
            'postcode' => '1000',
            'street' => 'Test street',
        ];

        app(CreateOrder::class)->handle(
            Cart::findOrFail($cartId),
            $customer,
            $address,
            $address,
            null,
        );
    }

    /**
     * Every id from `$position` onward, for actions taking a variable-length
     * list rather than a fixed set of operands.
     *
     * @return list<int>
     */
    private function idsFrom(int $position): array
    {
        /** @var list<string> $ids */
        $ids = $this->option('id');

        return array_map(intval(...), array_slice($ids, $position));
    }

    private function id(int $position): int
    {
        /** @var list<string> $ids */
        $ids = $this->option('id');

        return (int) $ids[$position];
    }

    private function intArg(int $position): int
    {
        /** @var list<string> $args */
        $args = $this->option('arg');

        return (int) $args[$position];
    }

    private function stringArg(int $position): string
    {
        /** @var list<string> $args */
        $args = $this->option('arg');

        return $args[$position];
    }

    private function cart(int $position): Cart
    {
        return Cart::findOrFail($this->id($position));
    }

    private function variation(int $position): ProductVariation
    {
        return ProductVariation::findOrFail($this->id($position));
    }
}
