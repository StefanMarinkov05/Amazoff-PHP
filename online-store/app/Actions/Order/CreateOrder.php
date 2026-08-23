<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Coupon\RedeemCoupon;
use App\Actions\Inventory\ReserveStock;
use App\Enums\AddressType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CartAlreadyCheckedOutException;
use App\Exceptions\CheckoutActorRemovedException;
use App\Exceptions\CouponNotApplicableException;
use App\Exceptions\EmptyCartException;
use App\Exceptions\InsufficientStockException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\User;
use App\Support\CalculateCouponDiscount;
use App\Support\CouponDiscountLine;
use App\Support\ResolveVariationPrice;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Places an order from a cart: recalculates totals server-side, snapshots
 * product and variation data onto `order_items`, creates the order and
 * both addresses, redeems the coupon if one is applied, and reserves
 * stock. Every order starts at `OrderStatus::New`, `PaymentStatus::Pending`,
 * for both payment methods.
 *
 * Locks `coupons` before `inventories` (one row per line, sorted by
 * `product_variation_id`). Full reasoning, the lock order, and known gaps:
 * `docs/reference/write-rules/order.md`.
 */
final class CreateOrder
{
    public function __construct(
        private readonly ReserveStock $reserveStock,
        private readonly RedeemCoupon $redeemCoupon,
    ) {}

    /**
     * @param  array<string, mixed>  $customer  email, phone, first_name,
     *                                          last_name, payment_method (PaymentMethod|string); optionally
     *                                          customer_note, invoice_required, invoice_company,
     *                                          invoice_vat_number, invoice_eik.
     * @param  array<string, mixed>  $billingAddress  `OrderAddress` columns:
     *                                                delivery_type, first_name, last_name, phone, country, city,
     *                                                postcode, plus street or courier_office_code/name; optionally
     *                                                source_address_id.
     * @param  array<string, mixed>  $deliveryAddress  Same shape as
     *                                                 `$billingAddress`.
     *
     * @throws EmptyCartException
     * @throws CouponNotApplicableException
     * @throws InsufficientStockException
     * @throws CartAlreadyCheckedOutException
     * @throws CheckoutActorRemovedException
     */
    public function handle(
        Cart $cart,
        array $customer,
        array $billingAddress,
        array $deliveryAddress,
        ?User $actor,
    ): Order {
        /** @var EloquentCollection<int, CartItem> $items */
        $items = $cart->cartItems()->with(['productVariation.product', 'productVariation.attributeValues'])->get();

        /** @var EloquentCollection<int, CartItem> $priceable */
        $priceable = $items->filter(function (CartItem $item): bool {
            /** @var ProductVariation|null $variation */
            $variation = $item->productVariation;

            return $variation !== null && $variation->product !== null;
        })->values();

        if ($priceable->isEmpty()) {
            throw EmptyCartException::atCheckout($cart);
        }

        // Priced exactly once per line, here, and threaded through every
        // downstream use — the order header, every order_item, and the
        // coupon discount calculation all read the same resolved figures
        // rather than each independently re-resolving current price. A
        // second, later resolution could legitimately disagree with the
        // first if a price changes mid-request, producing an order whose
        // own header does not sum to its own lines.
        $lines = $priceable->map(fn (CartItem $item): array => $this->resolveLine($item));

        $subtotal = $lines->reduce(fn (string $carry, array $line): string => bcadd($carry, $line['lineTotal'], 2), '0.00');
        $vat = $lines->reduce(fn (string $carry, array $line): string => bcadd($carry, $line['vatAmount'], 2), '0.00');

        $coupon = null;

        if ($cart->coupon_id !== null) {
            /** @var Coupon|null $coupon */
            $coupon = Coupon::query()->find($cart->coupon_id);

            // A gone coupon is not "no coupon was ever applied" — refuse the
            // same as any other invalidation rather than proceed at full
            // price silently. See CouponNotApplicableException::noLongerExists().
            if ($coupon === null) {
                throw CouponNotApplicableException::noLongerExists($cart->coupon_id);
            }
        }

        $discount = '0.00';

        if ($coupon !== null) {
            // Throws if the coupon no longer applies — nothing has been
            // written yet, so there is nothing to roll back.
            $discountLines = $lines->map(fn (array $line): CouponDiscountLine => new CouponDiscountLine(
                productId: $line['productId'],
                productCategoryId: $line['productCategoryId'],
                lineTotal: $line['lineTotal'],
                vatRate: $line['vatRate'],
            ));

            $amounts = CalculateCouponDiscount::forLines($coupon, $discountLines, $subtotal);
            $discount = $amounts['discount'];
            $vat = $amounts['vat'];
        }

        $shipping = '0.00'; // CalculateDeliveryPrice is slice 8, not built.
        $total = bcadd(bcsub($subtotal, $discount, 2), $shipping, 2);

        return DB::transaction(function () use (
            $cart,
            $lines,
            $customer,
            $billingAddress,
            $deliveryAddress,
            $actor,
            $coupon,
            $subtotal,
            $discount,
            $vat,
            $shipping,
            $total,
        ): Order {
            $order = $this->createOrderRow($cart, $customer, $actor, $subtotal, $discount, $shipping, $vat, $total);

            foreach ($lines as $line) {
                $this->createOrderItem($order, $line);
            }

            $this->createOrderAddress($order, $billingAddress, AddressType::Billing, $actor);
            $this->createOrderAddress($order, $deliveryAddress, AddressType::Delivery, $actor);

            if ($coupon !== null) {
                $this->redeemCoupon->handle($coupon, $order);
            }

            // Sorted by the locked resource's own primary key, not insertion
            // order, so 2 orders sharing lines never acquire in opposite
            // sequence.
            $sorted = $lines->sortBy(fn (array $line): int => $line['variation']->getKey());

            foreach ($sorted as $line) {
                /** @var ProductVariation $variation */
                $variation = $line['variation'];
                $this->reserveStock->handle($variation, $line['quantity'], $actor);
            }

            $order->update(['serial_number' => sprintf('ORD-%06d', $order->getKey())]);

            return $order->fresh(['orderItems', 'orderAddresses']);
        });
    }

    /**
     * Resolves everything one cart line needs — price, line total, VAT —
     * exactly once. Every other method reads the result rather than
     * calling `ResolveVariationPrice` a second time.
     *
     * @return array{item: CartItem, variation: ProductVariation, product: Product, quantity: int, unitPrice: string, lineTotal: string, vatRate: string, vatAmount: string, productId: int, productCategoryId: ?int}
     */
    private function resolveLine(CartItem $item): array
    {
        /** @var ProductVariation $variation */
        $variation = $item->productVariation;

        /** @var Product $product */
        $product = $variation->product;

        $price = ResolveVariationPrice::current($variation);
        $lineTotal = bcmul($price, (string) $item->quantity, 2);
        $vatRate = (string) $product->vat_rate;
        $vatAmount = bcdiv(bcmul($lineTotal, $vatRate, 4), bcadd('100', $vatRate, 4), 2);

        return [
            'item' => $item,
            'variation' => $variation,
            'product' => $product,
            'quantity' => $item->quantity,
            'unitPrice' => $price,
            'lineTotal' => $lineTotal,
            'vatRate' => $vatRate,
            'vatAmount' => $vatAmount,
            'productId' => $product->getKey(),
            'productCategoryId' => $product->product_category_id,
        ];
    }

    /**
     * `source_address_id`, if given, is scoped to `$actor` the same way
     * CLAUDE.md requires everywhere else (`auth()->user()->orders()->
     * findOrFail($id)`) — a query that cannot return another customer's
     * row, rather than a check against one already loaded. A guest has no
     * saved addresses to own, so any `source_address_id` from a guest
     * fails the same way.
     *
     * @param  array<string, mixed>  $address
     */
    private function createOrderAddress(Order $order, array $address, AddressType $type, ?User $actor): void
    {
        if (isset($address['source_address_id'])) {
            $address['source_address_id'] = $actor !== null
                ? $actor->addresses()->findOrFail($address['source_address_id'])->getKey()
                : throw (new ModelNotFoundException)->setModel(Address::class, [$address['source_address_id']]);
        }

        $model = new OrderAddress($address);
        $model->order_id = $order->getKey();
        $model->type = $type;
        $model->save();
    }

    /**
     * @param  array<string, mixed>  $customer
     *
     * @throws CartAlreadyCheckedOutException
     * @throws CheckoutActorRemovedException
     */
    private function createOrderRow(
        Cart $cart,
        array $customer,
        ?User $actor,
        string $subtotal,
        string $discount,
        string $shipping,
        string $vat,
        string $total,
    ): Order {
        try {
            /** @var Order $order */
            $order = Order::query()->create([
                'user_id' => $actor?->getKey(),
                'cart_id' => $cart->getKey(),
                // Placeholder, unique on its own — overwritten from the row's
                // own id once it exists, before this transaction commits.
                'serial_number' => (string) Str::uuid(),
                'email' => $customer['email'],
                'phone' => $customer['phone'],
                'first_name' => $customer['first_name'],
                'last_name' => $customer['last_name'],
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method' => $customer['payment_method'],
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'shipping_amount' => $shipping,
                'vat_amount' => $vat,
                'total_amount' => $total,
                'customer_note' => $customer['customer_note'] ?? null,
                'invoice_required' => $customer['invoice_required'] ?? false,
                'invoice_company' => $customer['invoice_company'] ?? null,
                'invoice_vat_number' => $customer['invoice_vat_number'] ?? null,
                'invoice_eik' => $customer['invoice_eik'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The only unique constraint this insert can realistically
            // collide on. serial_number is a fresh UUID per attempt — a
            // collision there is not a real-world case worth branching on.
            throw new CartAlreadyCheckedOutException($cart);
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'orders_user_id_foreign')) {
                throw $e;
            }

            // nullOnDelete() governs an existing child row, not a new insert
            // referencing an id that's already gone. A null $actor writes a
            // null user_id, which can never violate this FK, so reaching
            // this branch guarantees $actor is not null.
            assert($actor instanceof User);

            throw new CheckoutActorRemovedException($actor);
        }

        return $order;
    }

    /**
     * @param  array{item: CartItem, variation: ProductVariation, product: Product, quantity: int, unitPrice: string, lineTotal: string, vatRate: string, vatAmount: string, productId: int, productCategoryId: ?int}  $line
     */
    private function createOrderItem(Order $order, array $line): void
    {
        /** @var ProductVariation $variation */
        $variation = $line['variation'];

        /** @var Product $product */
        $product = $line['product'];

        OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'product_variation_id' => $variation->getKey(),
            'product_name' => $product->name,
            'product_sku' => $variation->sku,
            'variation_name' => $this->variationName($variation),
            'quantity' => $line['quantity'],
            'unit_price' => $line['unitPrice'],
            'line_total' => $line['lineTotal'],
            // Never per line — the coupon discount is an order-level
            // deduction, not a rewrite of line prices.
            'discount_amount' => '0.00',
            'vat_rate' => $line['vatRate'],
            'vat_amount' => $line['vatAmount'],
        ]);
    }

    /**
     * No column on `ProductVariation` names it — a variation is identified
     * by its attribute values (size, color, ...). Joined for a human-
     * readable snapshot; falls back to the SKU for a variation with none.
     */
    private function variationName(ProductVariation $variation): string
    {
        $values = $variation->attributeValues->pluck('value')->all();

        return $values === [] ? $variation->sku : implode(' / ', $values);
    }
}
