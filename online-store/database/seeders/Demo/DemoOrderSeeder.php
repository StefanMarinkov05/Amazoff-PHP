<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Coupon\ApplyCoupon;
use App\Actions\Order\CreateOrder;
use App\Actions\Order\TransitionOrderStatus;
use App\Actions\Payment\RecordPayment;
use App\Actions\Payment\TransitionPaymentStatus;
use App\Actions\Shipment\CreateShipment;
use App\Actions\Shipment\TransitionShipmentStatus;
use App\Enums\AddressType;
use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStatus;
use App\Models\Address;
use App\Models\Carrier;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Shipment;
use App\Models\ShipmentTrackingEvent;
use App\Models\User;
use App\Support\ProtectedSkus;
use App\Support\ResolveVariationPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * 158 orders — 140 spread across the whole customer base on a fixed status
 * distribution, plus 18 pinned to the two named demo accounts
 * (`SHOWCASE_ACCOUNTS`). `customer@example.com` holds all eleven
 * `OrderStatus` cases by itself, so a demo can walk the entire order
 * lifecycle on one login instead of hunting for an account that happens to
 * own the state it wants to show.
 *
 * Every one of them is checked out through real carts and walked through
 * `TransitionOrderStatus` exactly like a real order would be — never
 * fabricated with `Order::factory()`. `CreateOrder` composes `ReserveStock`
 * itself, so building the order this way is what makes the classic
 * "only 0 reserved" trap (`CompleteSale` on `=> Shipped`) structurally
 * impossible rather than something to remember. See
 * `misc/sonnet-phase2-data-brief.md` (gitignored) for the full plan this
 * class implements — the status distribution, payment/shipment coverage,
 * and coupon assignment below are all assigned by that plan, not sampled.
 *
 * `App\Support\ProtectedSkus` is consulted before any line is added to a
 * cart. Skipping it would silently drain the stock states
 * `docs/reference/schema/demo-data.md` documents for a live demo.
 *
 * No Action takes a date, so every order's timestamps and every row its
 * walk produces are back-dated afterward with direct Eloquent writes,
 * spread across the last 9 months. That is the one place in this seeder
 * where writing rows directly instead of through an Action is correct —
 * the thing being changed is a clock, not domain state.
 */
class DemoOrderSeeder extends Seeder
{
    private const TOTAL_ORDERS = 140;

    /**
     * Target status => count. Sums to TOTAL_ORDERS. Order in this array is
     * not meaningful; `originsFor()` decides where each one is walked from.
     *
     * @var array<string, int>
     */
    private const STATUS_DISTRIBUTION = [
        'delivered' => 62,
        'shipped' => 12,
        'ready_for_shipment' => 6,
        'preparing' => 8,
        'confirmed' => 10,
        'paid' => 6,
        'awaiting_payment' => 9,
        'new' => 7,
        'cancelled' => 12,
        'returned' => 5,
        'refunded' => 3,
    ];

    /**
     * The one account a demo signs in as, and every order state it holds.
     *
     * The 140-order distribution above spreads across 100 factory customers,
     * so any *particular* account ends up with somewhere between zero and a
     * handful of orders, on whatever statuses the shuffle happened to hand
     * it. That is correct for a realistic dataset and useless for a demo:
     * signing in as the account a presenter actually knows the password for
     * and finding an empty `/account/orders` is the common case, not the
     * unlucky one.
     *
     * So this set is pinned, and it is deliberately **exhaustive rather than
     * representative**: all eleven `OrderStatus` cases appear on this single
     * account, so the whole lifecycle can be walked through on one login
     * without signing out. `delivered` appears three times and `shipped`
     * twice because those are the states carrying the most downstream
     * variation worth showing — a partial refund, a review already written,
     * a shipment mid-transit — and one row each would force a choice between
     * them.
     *
     * `admin@example.com` keeps a small set of its own. Not for the panel,
     * which reads every order regardless of owner, but so the *storefront*
     * half of the app is reachable while signed in as staff — an order
     * history is what makes `/account/orders` show anything at all.
     *
     * These are *additional* to `STATUS_DISTRIBUTION`, not carved out of it:
     * carving would silently shrink whichever statuses were borrowed from,
     * and the distribution is asserted against the live database afterward.
     * `TOTAL_ORDERS` counts the distribution only; `reportActual()` reports
     * the real total including these.
     *
     * @var array<string, list<string>>
     */
    private const SHOWCASE_ACCOUNTS = [
        // Every OrderStatus case, on one account, in lifecycle order.
        'customer@example.com' => [
            'new',
            'awaiting_payment',
            'paid',
            'confirmed',
            'preparing',
            'ready_for_shipment',
            'shipped',
            'shipped',
            'delivered',
            'delivered',
            'delivered',
            'cancelled',
            'returned',
            'refunded',
        ],
        'admin@example.com' => [
            'delivered',
            'shipped',
            'confirmed',
            'cancelled',
        ],
    ];

    /**
     * Which showcase slots redeem a coupon, keyed by account and by index
     * into that account's status list above. Pinned rather than sampled for
     * the same reason the statuses are: a demo of the coupon line on an
     * order needs an order that definitely has one.
     *
     * Spread across the lifecycle on purpose — an in-flight order and a
     * settled one both carry a discount line, so the coupon is visible
     * whichever order the demo happens to open.
     *
     * Two constraints shaped this table, both found by a run failing rather
     * than by reading `ApplyCoupon`:
     *
     * **No code repeats within one account.** `CouponFactory` randomizes
     * `usage_limit_per_customer` per seed run, and `FLAT15` came up as 1 on
     * a real run — so a second assignment of the same code to the same
     * account was refused by `RedeemCoupon`, and because the coupon is
     * applied before checkout, the refusal took the whole order with it.
     * Assigning distinct codes removes the dependency on a randomized limit
     * entirely, rather than pinning the limit and coupling this table to
     * `DemoCouponSeeder`'s factory defaults.
     *
     * **Order-scoped codes only.** `TOOLDEAL` is `scope: products`, so
     * `ApplyCoupon` refuses it unless the cart holds one of its 3 assigned
     * products. `fillCart()` forces one in for that code, but only if one is
     * still sufficiently stocked — and `COUPON_ASSIGNMENT` already spends
     * `TOOLDEAL` 4 times against the same 3 products, so a 5th showcase call
     * competes for what is left and fails outright when it runs dry.
     * `TOOLDEAL`'s product-scoped behaviour is already demonstrated by those
     * 4; the showcase slots do not need to re-prove it at the cost of a
     * pinned status.
     *
     * Both failures were silent in the summary line — the run reported 157
     * of 158 created and the missing state was only visible by querying —
     * which is why `reportShowcaseAccounts()` now counts these against the
     * live database and says `SHORT` out loud.
     *
     * Only currently-valid coupons appear — `SUMMER20` (expired) and
     * `WINTER25` (scheduled) cannot be redeemed at all, by design, and
     * `ONEUSEONLY` is spent by the main distribution so that a further
     * checkout against it demonstrates the refusal live.
     *
     * @var array<string, array<int, string>>
     */
    private const SHOWCASE_COUPONS = [
        'customer@example.com' => [
            2 => 'WELCOME10',
            8 => 'FLAT15',
        ],
        'admin@example.com' => [
            0 => 'WELCOME10',
        ],
    ];

    private const GUEST_ORDER_COUNT = 28;

    private const COD_ORDER_COUNT = 56;

    /**
     * Coupon code => how many orders redeem it. Only coupons whose window is
     * currently valid can be redeemed — `SUMMER20` (expired) and `WINTER25`
     * (scheduled) are deliberately absent here; they exist to demonstrate
     * the boundary on the *product* fixture side, not to be redeemed.
     *
     * @var array<string, int>
     */
    private const COUPON_ASSIGNMENT = [
        'WELCOME10' => 8,
        'FLAT15' => 6,
        'TOOLDEAL' => 4,
        'ONEUSEONLY' => 1,
    ];

    protected ProtectedSkus $protectedSkus;

    /**
     * How many `Paid`-bound payments so far have been routed through a
     * `Failed` detour (2 of which recover to `Paid`) or a partial-refund
     * path, so each hits its exact target count across the whole run
     * instead of being left to a coin flip that might land on 0 or 8.
     */
    private int $failedRouted = 0;

    private int $failedRecovered = 0;

    private int $partialRefundsIssued = 0;

    private int $stackedPartialRefundsIssued = 0;

    /** @var Collection<int, ProductVariation> */
    protected Collection $selectableVariations;

    /** @var Collection<int, Carrier> */
    protected Collection $carriers;

    public function __construct(
        private readonly CreateOrder $createOrder,
        private readonly TransitionOrderStatus $transitionOrderStatus,
        private readonly RecordPayment $recordPayment,
        private readonly TransitionPaymentStatus $transitionPaymentStatus,
        private readonly CreateShipment $createShipment,
        private readonly TransitionShipmentStatus $transitionShipmentStatus,
        private readonly ApplyCoupon $applyCoupon,
    ) {
        $this->protectedSkus = new ProtectedSkus;
    }

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        // Provably true today (both operands are literal consts Larastan
        // evaluates at analysis time) — kept anyway as a guard against a
        // future edit to STATUS_DISTRIBUTION that forgets to update
        // TOTAL_ORDERS to match; that failure mode is exactly what static
        // analysis of *today's* values cannot catch.
        // @phpstan-ignore notIdentical.alwaysFalse
        if (array_sum(self::STATUS_DISTRIBUTION) !== self::TOTAL_ORDERS) {
            throw new RuntimeException('STATUS_DISTRIBUTION does not sum to TOTAL_ORDERS — fix the table before seeding.');
        }

        $this->loadPools();

        $plan = $this->buildPlan();
        $created = 0;
        $failed = 0;

        foreach ($plan as $spec) {
            try {
                $this->seedOneOrder($spec);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $this->command?->error("Order #{$created} plan failed ({$spec['target']}): ".$e->getMessage());
            }
        }

        $this->command?->info("DemoOrderSeeder: created {$created} order(s), {$failed} failed.");

        $this->seedPartialRefunds();

        $this->reportActual();
    }

    /**
     * `PartiallyRefunded` is not an order-status target — a partial refund
     * (a damaged item, one line sent back) leaves the order's own status
     * wherever it already was. So this runs after the main walk, against
     * whichever `Paid` payments exist, rather than being folded into
     * `applyHop`'s per-order-status logic.
     *
     * 2 payments take a single partial refund; 2 more take two successive
     * partial refunds that together still fit — the only accumulating
     * transition in the enum, and worth demonstrating on its own rather
     * than only ever seeing a single refund per payment.
     */
    private function seedPartialRefunds(): void
    {
        /** @var Collection<int, Payment> $candidates */
        $candidates = Payment::query()->where('status', PaymentStatus::Paid)->inRandomOrder()->limit(4)->get();

        foreach ($candidates as $payment) {
            $half = bcdiv((string) $payment->amount, '2', 2);

            $this->transitionPaymentStatus->handle($payment, PaymentStatus::PartiallyRefunded, null, $half);
            $this->partialRefundsIssued++;

            if ($this->stackedPartialRefundsIssued < 2) {
                $this->stackedPartialRefundsIssued++;
                $remaining = bcsub((string) $payment->amount, $half, 2);
                // Half of what is left — together with the first refund,
                // still strictly under the payment total.
                $second = bcdiv($remaining, '2', 2);

                if (bccomp($second, '0.00', 2) > 0) {
                    $this->transitionPaymentStatus->handle($payment, PaymentStatus::PartiallyRefunded, null, $second);
                }
            }
        }

        $this->command?->info("Issued {$this->partialRefundsIssued} partial refund(s), {$this->stackedPartialRefundsIssued} stacked.");
    }

    protected function loadPools(): void
    {
        /** @var Collection<int, ProductVariation> $all */
        $all = ProductVariation::query()
            ->with('product')
            ->where('is_available', true)
            ->get()
            ->filter(function (ProductVariation $v): bool {
                /** @var Product|null $product */
                $product = $v->product;

                return $product !== null && $product->is_available;
            });

        $this->selectableVariations = $all->reject(fn (ProductVariation $v): bool => $this->protectedSkus->isProtected($v))->values();

        if ($this->selectableVariations->isEmpty()) {
            throw new RuntimeException('No selectable (non-protected, available) variations found — seed the catalogue first.');
        }

        $this->carriers = Carrier::query()->where('is_active', true)->get();

        if ($this->carriers->isEmpty()) {
            throw new RuntimeException('No active carriers found — seed carriers first.');
        }
    }

    /**
     * One row per order to build: target status, origin path, method,
     * guest/registered, coupon code or null.
     *
     * @return list<array{target: string, origins: list<string>, method: PaymentMethod, guest: bool, coupon: string|null}>
     */
    private function buildPlan(): array
    {
        $plan = [];

        foreach (self::STATUS_DISTRIBUTION as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $plan[] = ['target' => $status, 'origin_index' => $i];
            }
        }

        shuffle($plan);

        // AwaitingPayment/Paid are Stripe-only targets — COD skips the
        // payment leg entirely (New => Confirmed directly), so a COD order
        // can never legitimately land there. Exclude those slots from the
        // COD pool before picking, rather than assigning COD at random and
        // discovering the conflict inside originsFor().
        $codEligible = array_keys(array_filter(
            $plan,
            fn (array $row): bool => ! in_array($row['target'], ['awaiting_payment', 'paid'], true),
        ));
        $codIndexes = array_flip(array_rand(array_flip($codEligible), self::COD_ORDER_COUNT));

        $guestIndexes = array_flip(array_rand($plan, self::GUEST_ORDER_COUNT));

        $couponIndexes = [];
        $available = array_keys($plan);
        foreach (self::COUPON_ASSIGNMENT as $code => $n) {
            $pick = array_rand(array_flip($available), $n);
            $pick = is_array($pick) ? $pick : [$pick];
            foreach ($pick as $idx) {
                $couponIndexes[$idx] = $code;
                $available = array_diff($available, [$idx]);
            }
        }

        $result = [];

        foreach ($plan as $idx => $row) {
            $target = OrderStatus::from($row['target']);
            $isCod = isset($codIndexes[$idx]);
            $isGuest = isset($guestIndexes[$idx]);
            $coupon = $couponIndexes[$idx] ?? null;

            // Coupons need a registered customer with a real cart; keep the
            // coupon-bearing slots off the guest set rather than fighting
            // both constraints on the same order.
            if ($coupon !== null && $isGuest) {
                $isGuest = false;
            }

            $method = $isCod ? PaymentMethod::CashOnDelivery : PaymentMethod::Stripe;

            $result[] = [
                'target' => $target->value,
                'origins' => $this->originsFor($target, $row['origin_index'], $method),
                'method' => $method,
                'guest' => $isGuest,
                'coupon' => $coupon,
                'owner' => null,
            ];
        }

        return [...$result, ...$this->buildShowcasePlan()];
    }

    /**
     * The pinned per-account orders described on `SHOWCASE_ACCOUNTS`.
     *
     * Payment method is derived from the target rather than chosen: a COD
     * order cannot legitimately reach `AwaitingPayment` or `Paid`, since COD
     * skips the payment leg entirely (`New => Confirmed`, the edge
     * `OrderStatus` carries for exactly this). Alternating the rest gives
     * each account both methods on its history without ever producing an
     * illegal pairing.
     *
     * @return list<array{target: string, origins: list<string>, method: PaymentMethod, guest: bool, coupon: string|null, owner: string|null}>
     */
    private function buildShowcasePlan(): array
    {
        $plan = [];

        foreach (self::SHOWCASE_ACCOUNTS as $email => $targets) {
            foreach ($targets as $slot => $targetValue) {
                $target = OrderStatus::from($targetValue);

                $stripeOnly = in_array($targetValue, ['awaiting_payment', 'paid'], true);
                $method = ($stripeOnly || $slot % 2 === 0)
                    ? PaymentMethod::Stripe
                    : PaymentMethod::CashOnDelivery;

                $plan[] = [
                    'target' => $target->value,
                    'origins' => $this->originsFor($target, $slot, $method),
                    'method' => $method,
                    'guest' => false,
                    'coupon' => self::SHOWCASE_COUPONS[$email][$slot] ?? null,
                    'owner' => $email,
                ];
            }
        }

        return $plan;
    }

    /**
     * A showcase account, by email. Fails loudly rather than falling back to
     * a random customer: a silent fallback would produce a run that reports
     * success while leaving the account the demo is about with no orders,
     * which is the exact failure SHOWCASE_ACCOUNTS exists to prevent.
     */
    private function customerByEmail(string $email): User
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            throw new RuntimeException(
                "Showcase account [{$email}] does not exist — run the System\\UserSeeder (php artisan migrate:fresh --seed) before seeding demo orders."
            );
        }

        return $user;
    }

    /**
     * The full walk of statuses to pass through to reach $target.
     *
     * COD skips `AwaitingPayment`/`Paid` entirely — `New => Confirmed`
     * exists in the enum specifically for that path (see `OrderStatus`'s
     * own docblock). A COD walk therefore never mentions either status;
     * a Stripe walk always does, picking among the origins the brief
     * allows via $slot so every origin actually gets used.
     *
     * @return list<string>
     */
    private function originsFor(OrderStatus $target, int $slot, PaymentMethod $method): array
    {
        $isCod = $method === PaymentMethod::CashOnDelivery;

        // Stripe: New -> AwaitingPayment -> Paid -> Confirmed -> ...
        // COD:    New -> Confirmed -> ...
        $preConfirmed = $isCod ? ['new'] : ['new', 'awaiting_payment', 'paid'];

        return match ($target) {
            OrderStatus::New => ['new'],
            OrderStatus::AwaitingPayment => ['new', 'awaiting_payment'],
            OrderStatus::Paid => ['new', 'awaiting_payment', 'paid'],
            OrderStatus::Confirmed => [...$preConfirmed, 'confirmed'],
            OrderStatus::Preparing => [...$preConfirmed, 'confirmed', 'preparing'],
            OrderStatus::ReadyForShipment => [...$preConfirmed, 'confirmed', 'preparing', 'ready_for_shipment'],
            OrderStatus::Shipped => [...$preConfirmed, 'confirmed', 'preparing', 'ready_for_shipment', 'shipped'],
            OrderStatus::Delivered => [...$preConfirmed, 'confirmed', 'preparing', 'ready_for_shipment', 'shipped', 'delivered'],
            // Paid cannot transition to Cancelled (PaymentStatus's own
            // matrix — a paid order that's cancelled needs a refund, not a
            // cancelled payment), so only an order cancelled from New or
            // AwaitingPayment can ever produce a Cancelled *payment*
            // status. Widened past the original 3 slots so that state has
            // more than a token presence.
            OrderStatus::Cancelled => match (true) {
                $slot < 2 => ['new', 'cancelled'],
                $slot < 9 && ! $isCod => ['new', 'awaiting_payment', 'cancelled'],
                $slot < 10 => [...$preConfirmed, 'confirmed', 'cancelled'],
                default => [...$preConfirmed, 'confirmed', 'preparing', 'cancelled'],
            },
            OrderStatus::Returned => $slot < 2
                ? [...$preConfirmed, 'confirmed', 'preparing', 'ready_for_shipment', 'shipped', 'returned']
                : [...$preConfirmed, 'confirmed', 'preparing', 'ready_for_shipment', 'shipped', 'delivered', 'returned'],
            // A Refunded order-status implies money was actually collected
            // and is now being given back — a COD order that never reached
            // Delivered never opened a payment at all (COD is marked paid
            // on remittance, not before), so there is nothing to refund.
            // Every COD path here is routed through the full Delivered ->
            // Returned walk, where CashOnDelivery's payment leg in
            // applyHop's Delivered branch guarantees a payment exists.
            OrderStatus::Refunded => match (true) {
                $slot === 0 && ! $isCod => ['new', 'awaiting_payment', 'paid', 'refunded'],
                $slot === 1 && ! $isCod => ['new', 'awaiting_payment', 'cancelled', 'refunded'],
                // Delivered, not a direct Shipped => Returned: for COD this
                // is the only hop that opens a payment at all (applyHop's
                // Delivered branch), and every Refunded target needs one to
                // refund. Legal for Stripe too — just one extra real hop.
                default => [...$preConfirmed, 'confirmed', 'preparing', 'ready_for_shipment', 'shipped', 'delivered', 'returned', 'refunded'],
            },
        };
    }

    /**
     * @param  array{target: string, origins: list<string>, method: PaymentMethod, guest: bool, coupon: string|null, owner?: string|null}  $spec
     */
    protected function seedOneOrder(array $spec): void
    {
        $method = $spec['method'];
        $isGuest = $spec['guest'];
        $walk = $spec['origins'];

        // A pinned owner is the showcase pass (see SHOWCASE_ACCOUNTS): those
        // orders must land on a known email so a demo can sign in and find
        // them, rather than on whichever of the 100 factory customers the
        // random draw happened to pick.
        $owner = $spec['owner'] ?? null;

        $customer = match (true) {
            $owner !== null => $this->customerByEmail($owner),
            $isGuest => null,
            default => $this->randomRegisteredCustomer(),
        };

        if (! $isGuest && $customer === null) {
            throw new InvalidArgumentException('A non-guest order must resolve a customer.');
        }

        $cart = $isGuest
            ? Cart::query()->create(['session_id' => (string) Str::uuid()])
            : Cart::query()->create(['user_id' => $customer->getKey()]);

        $this->fillCart($cart, $spec['coupon']);

        if ($spec['coupon'] !== null) {
            $this->applyCoupon->handle($cart, $spec['coupon']);
        }

        $billing = $this->addressFor($customer, AddressType::Billing);
        $delivery = $this->addressFor($customer, AddressType::Delivery);

        $order = $this->createOrder->handle(
            cart: $cart,
            customer: [
                'email' => $isGuest ? fake()->safeEmail() : $customer->email,
                'phone' => fake()->numerify('+359#########'),
                'first_name' => $isGuest ? fake()->firstName() : $customer->first_name,
                'last_name' => $isGuest ? fake()->lastName() : $customer->last_name,
                'payment_method' => $method,
            ],
            billingAddress: $billing,
            deliveryAddress: $delivery,
            // Unlike every other Action this seeder calls, CreateOrder's
            // $actor is not an authorization gate — it is the checking-out
            // customer, written straight to orders.user_id and used to scope
            // any source_address_id. Passing null here for a registered
            // customer is what was throwing ModelNotFoundException on every
            // saved-address order: createOrderAddress() refuses a
            // source_address_id from a null actor unconditionally, the same
            // refusal a real guest submitting one would hit.
            actor: $customer,
        );

        // Walk every hop after `New` (CreateOrder already produced `New`).
        foreach (array_slice($walk, 1) as $hopValue) {
            $this->applyHop($order, OrderStatus::from($hopValue), $method);
        }

        $this->backDate($order);
    }

    private function applyHop(Order $order, OrderStatus $to, PaymentMethod $method): void
    {
        // COD is marked paid on remittance: only after Delivered, never
        // before. Every other hop for a COD order skips the payment leg
        // entirely (New => Confirmed directly, per the enum's own comment).
        if ($to === OrderStatus::Shipped) {
            $carrier = $this->carriers->random();
            $shipment = $this->createShipment->handle($order, $carrier, null);
            $this->transitionOrderStatus->handle($order, $to, null);
            $this->walkShipment($shipment, ShipmentStatus::Shipped);

            return;
        }

        if ($to === OrderStatus::Delivered) {
            $this->transitionOrderStatus->handle($order, $to, null);

            /** @var Shipment|null $shipment */
            $shipment = $order->shipment()->first();

            if ($shipment !== null) {
                $target = random_int(1, 100) <= 65 ? ShipmentStatus::InTransit : null;

                if ($target !== null) {
                    $this->walkShipment($shipment, ShipmentStatus::InTransit);
                }

                $this->walkShipment($shipment, ShipmentStatus::Delivered);
            }

            if ($method === PaymentMethod::CashOnDelivery) {
                $payment = $this->recordPayment->handle($order, $method, null);
                $this->transitionPaymentStatus->handle($payment, PaymentStatus::Paid, null);
            }

            return;
        }

        if ($to === OrderStatus::Returned) {
            $this->transitionOrderStatus->handle($order, $to, null);

            /** @var Shipment|null $shipment */
            $shipment = $order->shipment()->first();

            if ($shipment !== null && $shipment->status !== ShipmentStatus::Returned) {
                $this->walkShipment($shipment, ShipmentStatus::Returned);
            }

            return;
        }

        if ($to === OrderStatus::Refunded) {
            $this->transitionOrderStatus->handle($order, $to, null);

            /** @var Payment|null $payment */
            $payment = $order->payment()->first();

            if ($payment !== null && ! in_array($payment->status, [PaymentStatus::Refunded, PaymentStatus::Cancelled], true)) {
                $this->transitionPaymentStatus->handle($payment, PaymentStatus::Refunded, null);
            }

            return;
        }

        if ($to === OrderStatus::Cancelled) {
            /** @var Payment|null $payment */
            $payment = $order->payment()->first();

            if ($payment !== null && $payment->status->canTransitionTo(PaymentStatus::Cancelled)) {
                $this->transitionPaymentStatus->handle($payment, PaymentStatus::Cancelled, null);
            }

            $this->transitionOrderStatus->handle($order, $to, null);

            return;
        }

        // AwaitingPayment, Paid, Confirmed, Preparing, ReadyForShipment.
        if ($to === OrderStatus::AwaitingPayment) {
            $this->transitionOrderStatus->handle($order, $to, null);
            $this->recordPayment->handle($order, $method, null);

            return;
        }

        if ($to === OrderStatus::Paid) {
            $this->transitionOrderStatus->handle($order, $to, null);
            /** @var Payment $payment */
            $payment = $order->payment()->firstOrFail();
            $this->walkPaymentToPaid($payment);

            return;
        }

        if ($to === OrderStatus::Confirmed && $method === PaymentMethod::CashOnDelivery) {
            // COD skips AwaitingPayment/Paid entirely: New => Confirmed.
            $this->transitionOrderStatus->handle($order, $to, null);

            return;
        }

        $this->transitionOrderStatus->handle($order, $to, null);
    }

    private function walkPaymentToPaid(Payment $payment): void
    {
        // 4 of the ~95 Paid-bound payments detour through Failed first (2 of
        // which then recover) — a pinned count, not a coin flip, so Failed
        // is guaranteed to appear rather than depending on luck.
        if ($this->failedRouted < 4) {
            $this->failedRouted++;
            $this->transitionPaymentStatus->handle($payment, PaymentStatus::Failed, null);

            if ($this->failedRecovered >= 2) {
                // The other 2 stay Failed — a real payment failure the
                // customer never retried, not every failure recovering.
                return;
            }

            $this->failedRecovered++;
        }

        // 20 of the ~95 Paid payments go through Processing on the way;
        // the rest go straight Pending => Paid (or Failed => Paid, for the
        // 2 recovering above). No counter here — this is "some of them,"
        // not a pinned count, so a coin flip is enough.
        if (random_int(1, 100) <= 21) {
            $this->transitionPaymentStatus->handle($payment, PaymentStatus::Processing, null);
        }

        $this->transitionPaymentStatus->handle($payment, PaymentStatus::Paid, null);
    }

    private function walkShipment(Shipment $shipment, ShipmentStatus $to): void
    {
        $this->transitionShipmentStatus->handle(
            $shipment,
            $to,
            null,
            $this->rawStatusFor($to),
            $this->trackingDescriptionFor($to),
        );
    }

    private function rawStatusFor(ShipmentStatus $status): string
    {
        return match ($status) {
            ShipmentStatus::Pending => 'PENDING',
            ShipmentStatus::Shipped => 'PICKED_UP',
            ShipmentStatus::InTransit => 'IN_TRANSIT',
            ShipmentStatus::Delivered => 'DELIVERED',
            ShipmentStatus::Returned => 'RETURNED_TO_SENDER',
            ShipmentStatus::Cancelled => 'CANCELLED',
        };
    }

    private function trackingDescriptionFor(ShipmentStatus $status): string
    {
        return match ($status) {
            ShipmentStatus::Shipped => 'Parcel picked up from warehouse.',
            ShipmentStatus::InTransit => 'Parcel in transit to destination hub.',
            ShipmentStatus::Delivered => 'Parcel delivered to recipient.',
            ShipmentStatus::Returned => 'Parcel returned to sender.',
            default => 'Status update.',
        };
    }

    private function randomRegisteredCustomer(): User
    {
        /** @var User $customer */
        $customer = User::query()->doesntHave('roles')->inRandomOrder()->firstOrFail();

        return $customer;
    }

    private function fillCart(Cart $cart, ?string $couponCode): void
    {
        // Weighted rather than uniform: most real baskets are one or two
        // lines, and a flat random_int(1, 6) would make a six-line order as
        // common as a single-line one, which reads as generated data the
        // moment anyone scrolls the order list. The long tail is what makes
        // the multi-line order-items table worth looking at on a demo, so it
        // has to exist — just not as often as the common case.
        $roll = random_int(1, 100);
        $target = match (true) {
            $roll <= 34 => 1,
            $roll <= 62 => 2,
            $roll <= 80 => 3,
            $roll <= 91 => 4,
            $roll <= 97 => 5,
            default => 6,
        };

        $addedVariationIds = [];

        // TOOLDEAL is scope=products: ApplyCoupon refuses it unless the cart
        // actually contains one of its 3 assigned products. Force one in
        // rather than hoping a random draw hits it — with a 200-variation
        // pool and 3 eligible SKUs, it essentially never would.
        if ($couponCode === 'TOOLDEAL') {
            $forced = $this->forcedVariationFor($couponCode);

            if ($forced !== null) {
                $quantity = $this->protectedSkus->floorFor($forced);
                CartItem::query()->create([
                    'cart_id' => $cart->getKey(),
                    'product_variation_id' => $forced->getKey(),
                    'quantity' => $quantity,
                ]);
                $addedVariationIds[] = $forced->getKey();
            }
        }

        // Oversample the shuffle: across 140 orders sharing one 200-variation
        // pool, plenty of variations run low on stock as the run progresses.
        // Trying more candidates than $target lets fillCart skip an
        // insufficiently-stocked one and still reach $target lines, instead
        // of writing an undersized cart or, worse, asking CreateOrder to
        // reserve more than is available and throwing.
        $candidates = $this->selectableVariations->shuffle()->take($target * 6);

        $added = count($addedVariationIds);
        $subtotal = $addedVariationIds === [] ? 0.0 : $this->cartSubtotal($cart);

        // A coupon's minimum_order_value is randomized per seed run by
        // CouponFactory's defaults (DemoCouponSeeder only pins code/type/
        // scope/value/dates/is_active) — read it live rather than assuming
        // any fixed figure, or this breaks the next time the catalogue is
        // re-seeded with a different random minimum.
        $minimumFloor = $this->minimumOrderValueFor($couponCode);

        foreach ($candidates as $variation) {
            if ($added >= $target && ($minimumFloor === null || $subtotal >= $minimumFloor)) {
                break;
            }

            if (in_array($variation->getKey(), $addedVariationIds, true)) {
                continue;
            }

            $this->protectedSkus->assertSelectable($variation);

            $floor = $this->protectedSkus->floorFor($variation);

            // Mostly small quantities with an occasional bulk line, for the
            // same reason the line count above is weighted — and never below
            // the variation's own min_order_quantity floor, which
            // `AddToCart` would refuse.
            $quantity = random_int(1, 100) <= 12
                ? max($floor, random_int($floor + 2, $floor + 6))
                : max($floor, random_int($floor, $floor + 2));

            if (! $this->hasAvailableStock($variation, $quantity)) {
                continue;
            }

            CartItem::query()->create([
                'cart_id' => $cart->getKey(),
                'product_variation_id' => $variation->getKey(),
                'quantity' => $quantity,
            ]);
            $addedVariationIds[] = $variation->getKey();
            $added++;
            $subtotal += (float) ResolveVariationPrice::current($variation) * $quantity;
        }

        if ($added === 0) {
            throw new RuntimeException(
                'fillCart could not find any sufficiently-stocked, non-protected variation for this cart. '
                .'The selectable pool may be running low across the run — consider widening it.'
            );
        }
    }

    private function minimumOrderValueFor(?string $couponCode): ?float
    {
        if ($couponCode === null) {
            return null;
        }

        $coupon = Coupon::query()->where('code', $couponCode)->first();

        if ($coupon === null || $coupon->minimum_order_value === null) {
            return null;
        }

        return (float) $coupon->minimum_order_value;
    }

    private function forcedVariationFor(string $couponCode): ?ProductVariation
    {
        $coupon = Coupon::query()->where('code', $couponCode)->first();

        if ($coupon === null) {
            return null;
        }

        $productIds = $coupon->products()->pluck('products.id');

        $eligible = $this->selectableVariations->filter(
            fn (ProductVariation $v): bool => in_array($v->product_id, $productIds->all(), true)
        );

        /** @var ProductVariation|null $pick */
        $pick = $eligible->first(fn (ProductVariation $v): bool => $this->hasAvailableStock($v, $this->protectedSkus->floorFor($v)));

        return $pick;
    }

    private function cartSubtotal(Cart $cart): float
    {
        $total = 0.0;

        /** @var Collection<int, CartItem> $items */
        $items = $cart->cartItems()->with('productVariation')->get();

        foreach ($items as $item) {
            /** @var ProductVariation|null $variation */
            $variation = $item->productVariation;

            if ($variation === null) {
                continue;
            }

            $total += (float) ResolveVariationPrice::current($variation) * $item->quantity;
        }

        return $total;
    }

    private function hasAvailableStock(ProductVariation $variation, int $quantity): bool
    {
        /** @var Inventory|null $inventory */
        $inventory = $variation->inventory()->first();

        return $inventory !== null && $inventory->available() >= $quantity;
    }

    /**
     * @return array<string, mixed>
     */
    private function addressFor(?User $customer, AddressType $type): array
    {
        $useOffice = random_int(1, 140) <= 45;

        $base = [
            'delivery_type' => $useOffice ? DeliveryType::Office : DeliveryType::Address,
            'first_name' => $customer !== null ? $customer->first_name : fake()->firstName(),
            'last_name' => $customer !== null ? $customer->last_name : fake()->lastName(),
            'phone' => fake()->numerify('+359#########'),
            'country' => 'BG',
            'city' => fake()->randomElement(['Sofia', 'Plovdiv', 'Varna', 'Burgas', 'Ruse']),
            'postcode' => fake()->numerify('####'),
        ];

        if ($useOffice) {
            $base['courier_office_code'] = 'OFF-'.fake()->numerify('###');
            $base['courier_office_name'] = fake()->city().' Office';
        } else {
            $base['street'] = fake()->streetName().' '.fake()->buildingNumber();
        }

        if ($customer !== null) {
            /** @var Address|null $saved */
            $saved = $customer->addresses()->inRandomOrder()->first();

            if ($saved !== null) {
                $base['source_address_id'] = $saved->getKey();
                $base['city'] = $saved->city;
                $base['postcode'] = $saved->postcode;

                if (! $useOffice) {
                    $base['street'] = $saved->street;
                }
            }
        }

        return $base;
    }

    private function backDate(Order $order): void
    {
        // Weighted toward the recent end of the last 9 months — a shop that
        // grew, not a flat distribution.
        $daysAgo = (int) round(270 * (1 - sqrt(random_int(0, 1000) / 1000)));
        $createdAt = Carbon::now()->subDays($daysAgo)->subHours(random_int(0, 23));

        $order->refresh();
        $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        $histories = $order->orderStatusHistories()->orderBy('id')->get();
        $cursor = $createdAt->copy();

        foreach ($histories as $history) {
            $cursor = $cursor->copy()->addHours(random_int(2, 48));
            $history->forceFill(['created_at' => $cursor, 'updated_at' => $cursor])->saveQuietly();
        }

        /** @var Shipment|null $shipment */
        $shipment = $order->shipment()->first();
        $shipmentAt = null;
        $deliveredAt = null;

        if ($shipment !== null) {
            $shipmentAt = $createdAt->copy()->addDays(random_int(1, 3));
            $shipment->forceFill(['created_at' => $shipmentAt, 'updated_at' => $shipmentAt])->saveQuietly();

            $trackingCursor = $shipmentAt->copy();

            /** @var Collection<int, ShipmentTrackingEvent> $trackingEvents */
            $trackingEvents = $shipment->shipmentTrackingEvents()->orderBy('id')->get();

            foreach ($trackingEvents as $event) {
                $trackingCursor = $trackingCursor->copy()->addHours(random_int(4, 30));
                $event->forceFill(['created_at' => $trackingCursor, 'updated_at' => $trackingCursor])->saveQuietly();

                /** @var ShipmentStatus $eventStatus */
                $eventStatus = $event->status;

                if ($eventStatus === ShipmentStatus::Delivered) {
                    $deliveredAt = $trackingCursor->copy();
                }
            }
        }

        /** @var Payment|null $payment */
        $payment = $order->payment()->first();

        if ($payment !== null) {
            // A COD payment is only opened after delivery — placing it a
            // few hours after $createdAt, as every other payment method
            // does, would put the payment before the shipment and delivery
            // it actually followed. Anchor it to $deliveredAt when one
            // exists; otherwise (Stripe, or COD that never reached
            // Delivered) the order-creation anchor is correct.
            $paymentAt = $deliveredAt !== null
                ? $deliveredAt->copy()->addHours(random_int(0, 4))
                : $createdAt->copy()->addHours(random_int(1, 12));

            // RecordPayment sets paid_at to the wall-clock time it ran at
            // (correct for real usage — a payment is recorded when it
            // happens). A seeder replaying months of history has to
            // backdate that alongside created_at/updated_at, or every
            // paid_at in the demo set reads as "whenever this seeder last
            // ran" regardless of the order it belongs to — which breaks
            // anything grouping revenue by date. Only touch it when it was
            // actually set: Pending/Cancelled/Failed payments have no
            // paid_at to backdate.
            $payment->forceFill([
                'created_at' => $paymentAt,
                'updated_at' => $paymentAt,
                'paid_at' => $payment->paid_at !== null ? $paymentAt : null,
            ])->saveQuietly();
        }

        $redemption = CouponRedemption::query()->where('order_id', $order->getKey())->first();

        if ($redemption !== null) {
            $redemption->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
        }
    }

    private function reportActual(): void
    {
        $this->command?->info('--- Verifying against the live database ---');

        // Query builder rather than Eloquent's own pluck(): 'c' is a
        // selectRaw() alias, not a model property, and Larastan objects to
        // pretending otherwise.
        /** @var Collection<string, int> $statuses */
        $statuses = Order::query()->selectRaw('status, count(*) c')->groupBy('status')->toBase()->pluck('c', 'status');
        foreach ($statuses as $status => $count) {
            $this->command?->line("  status={$status}: {$count}");
        }

        $this->command?->info('Guest orders: '.Order::query()->whereNull('user_id')->count());
        $this->command?->info('Coupon redemptions: '.CouponRedemption::count());
        $this->command?->info('Payments by status:');

        /** @var Collection<string, int> $paymentStatuses */
        $paymentStatuses = Payment::query()->selectRaw('status, count(*) c')->groupBy('status')->toBase()->pluck('c', 'status');

        foreach ($paymentStatuses as $s => $c) {
            $this->command?->line("  {$s}: {$c}");
        }
        $this->command?->info('Shipments by status:');

        /** @var Collection<string, int> $shipmentStatuses */
        $shipmentStatuses = Shipment::query()->selectRaw('status, count(*) c')->groupBy('status')->toBase()->pluck('c', 'status');

        foreach ($shipmentStatuses as $s => $c) {
            $this->command?->line("  {$s}: {$c}");
        }

        $this->reportShowcaseAccounts();
    }

    /**
     * Counts what each showcase account actually ended up owning.
     *
     * `run()` catches a per-order failure and continues, which is right for a
     * 140-order set where one exhausted variation should not abort the run —
     * but it means a showcase account can silently end up with 8 orders
     * instead of 10. That is invisible in the summary line above, and it is
     * precisely the thing a presenter finds out about mid-demo. So it is
     * checked here, against the live rows, and said out loud when short.
     */
    private function reportShowcaseAccounts(): void
    {
        $this->command?->info('Showcase accounts:');

        foreach (self::SHOWCASE_ACCOUNTS as $email => $targets) {
            $expected = count($targets);
            $actual = Order::query()
                ->whereHas('user', function (Builder $q) use ($email): Builder {
                    /** @var Builder<User> $q */
                    return $q->where('email', $email);
                })
                ->count();

            $line = "  {$email}: {$actual} order(s), expected {$expected}";

            if ($actual < $expected) {
                $this->command?->warn($line.' — SHORT');

                continue;
            }

            $this->command?->line($line);
        }
    }
}
