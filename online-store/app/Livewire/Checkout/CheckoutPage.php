<?php

declare(strict_types=1);

namespace App\Livewire\Checkout;

use App\Actions\Order\CreateOrder;
use App\Actions\Payment\CreateStripeIntent;
use App\Actions\Payment\RecordPayment;
use App\Enums\DeliveryType;
use App\Enums\PaymentMethod;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Support\CalculateCartTotals;
use App\Support\ResolveCurrentCart;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Checkout — §37 criteria 6, 7 and 8.
 *
 * Guest (#6) and registered (#7) are the same flow, not two: the only
 * difference is that a signed-in customer's details are prefilled and the
 * order carries `user_id`. `CreateOrder` records the actor it is given and
 * never infers one from a matching email, so a guest ordering with the same
 * address as a registered account stays a guest order.
 *
 * ## The total is never taken from the browser (#8)
 *
 * Nothing here submits a price. `totals()` is a computed property read from
 * `CalculateCartTotals` for display, and `CreateOrder` recalculates the whole
 * order from the cart's own rows regardless of what this component showed.
 * The figure the customer saw and the figure they are charged are computed
 * from the same source twice, not passed between layers.
 *
 * ## Why the write is a single method rather than a chain of events
 *
 * `CreateOrder` → `RecordPayment` → `CreateStripeIntent` is one logical act
 * with three writes, and a half-finished checkout is the worst outcome: an
 * order reserving stock with no payment row, or a payment with no intent.
 * They run inside one transaction so the whole thing lands or none of it
 * does.
 *
 * The Stripe API call sits *inside* that transaction, which is normally
 * worth avoiding — a slow network call holding a database transaction open.
 * It is deliberate here: an intent created against an order that then rolls
 * back is a charge the customer could complete for an order that does not
 * exist. Stripe's `idempotency_key` on the intent means the retry after a
 * rollback returns the same intent rather than creating a second one, so the
 * cost of the occasional orphaned intent is bounded and the alternative —
 * an order that exists with no way to pay for it — is not.
 */
#[Layout('components.layouts.app')]
class CheckoutPage extends Component
{
    public string $email = '';

    public string $phone = '';

    public string $first_name = '';

    public string $last_name = '';

    /**
     * Card by default.
     *
     * Cash on delivery is the slower path for the shop — the money arrives
     * days later, via the courier, and needs a remittance step — so the
     * default should be the one that settles at checkout. A customer who
     * wants COD selects it; a customer who does not gets the card fields
     * without a click.
     */
    public string $payment_method = PaymentMethod::Stripe->value;

    public string $delivery_type = DeliveryType::Address->value;

    public string $country = 'BG';

    public string $city = '';

    public string $postcode = '';

    public string $street = '';

    public string $courier_office_code = '';

    public string $courier_office_name = '';

    public bool $billing_same_as_delivery = true;

    public string $billing_city = '';

    public string $billing_postcode = '';

    public string $billing_street = '';

    public string $customer_note = '';

    /**
     * Set once the order is placed; drives the payment step. Both are
     * written only by placeOrder() on the server and never legitimately come
     * from the client, so both are `#[Locked]` — a public property is
     * re-hydrated from the client on every update, and an unlocked `orderId`
     * let a crafted request point the page at another customer's order.
     * `#[Locked]` blocks the tampering; `order()` below still scopes the
     * lookup, so the id is safe even if it arrives some other way.
     * See `reference/security-testing.md` SEC-002.
     */
    #[Locked]
    public ?int $orderId = null;

    #[Locked]
    public ?string $clientSecret = null;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            // Prefill only. The customer can still change any of it, and
            // whatever they submit is what gets snapshotted onto the order.
            $this->email = $user->email;
            $this->first_name = $user->first_name;
            $this->last_name = $user->last_name;
            $this->phone = (string) ($user->phone ?? '');
        }
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'first_name' => ['required', 'string', 'min:2', 'max:50'],
            'last_name' => ['required', 'string', 'min:2', 'max:50'],
            'payment_method' => ['required', 'string', 'in:'.implode(',', array_column(PaymentMethod::cases(), 'value'))],
            'delivery_type' => ['required', 'string', 'in:'.implode(',', array_column(DeliveryType::cases(), 'value'))],
            'country' => ['required', 'string', 'size:2'],
            'city' => ['required', 'string', 'max:50'],
            'postcode' => ['required', 'string', 'max:20'],
            // Exactly one of the two address shapes, decided by delivery_type
            // rather than by which fields happen to be filled.
            'street' => ['nullable', 'required_if:delivery_type,address', 'string', 'max:150'],
            'courier_office_code' => ['nullable', 'required_if:delivery_type,office', 'string', 'max:50'],
            'courier_office_name' => ['nullable', 'required_if:delivery_type,office', 'string', 'max:150'],
            'billing_same_as_delivery' => ['boolean'],
            'billing_city' => ['nullable', 'required_if:billing_same_as_delivery,false', 'string', 'max:50'],
            'billing_postcode' => ['nullable', 'required_if:billing_same_as_delivery,false', 'string', 'max:20'],
            'billing_street' => ['nullable', 'required_if:billing_same_as_delivery,false', 'string', 'max:150'],
            'customer_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    #[Computed]
    public function cart(): Cart
    {
        return ResolveCurrentCart::forVisitor();
    }

    /** @return array{subtotal: string, vat: string, total: string} */
    #[Computed]
    public function totals(): array
    {
        return CalculateCartTotals::forCart($this->cart());
    }

    #[Computed]
    public function isEmpty(): bool
    {
        return $this->cart()->cartItems()->count() === 0;
    }

    public function placeOrder(): void
    {
        $validated = $this->validate();

        if ($this->isEmpty()) {
            $this->addError('email', 'Your basket is empty.');

            return;
        }

        $actor = auth()->user() instanceof User ? auth()->user() : null;
        $method = PaymentMethod::from($validated['payment_method']);

        try {
            [$order, $clientSecret] = DB::transaction(function () use ($validated, $actor, $method): array {
                $order = app(CreateOrder::class)->handle(
                    $this->cart(),
                    [
                        'email' => $validated['email'],
                        'phone' => $validated['phone'],
                        'first_name' => $validated['first_name'],
                        'last_name' => $validated['last_name'],
                        'payment_method' => $method,
                        'customer_note' => $validated['customer_note'] ?: null,
                    ],
                    $this->addressPayload(billing: true),
                    $this->addressPayload(billing: false),
                    $actor,
                );

                $payment = app(RecordPayment::class)->handle($order, $method, $actor);

                // COD skips Stripe entirely — CLAUDE.md, "Scope". There is no
                // intent to create and nothing for the browser to confirm.
                if ($method !== PaymentMethod::Stripe) {
                    return [$order, null];
                }

                $createIntent = app(CreateStripeIntent::class);
                $payment = $createIntent->handle($payment, $actor);

                return [$order, $createIntent->clientSecretFor($payment)];
            });
        } catch (InvalidArgumentException|RuntimeException $e) {
            // Domain refusals — an emptied cart, a coupon that expired
            // between the cart page and here, stock taken by someone else,
            // the same cart checked out twice in two tabs. All are things the
            // customer can act on, so they surface on the form rather than as
            // a 500.
            $this->addError('email', $e->getMessage());

            return;
        }

        // Entitles this session — and only this session — to view the
        // confirmation. OrderConfirmation refuses a bare id otherwise, since
        // serial numbers are sequential and would otherwise enumerate every
        // customer's address.
        session([OrderConfirmation::SESSION_KEY => $order->getKey()]);

        if ($clientSecret === null) {
            // COD: nothing to confirm in the browser.
            $this->redirectRoute('checkout.confirmation', ['order' => $order->getKey()], navigate: true);

            return;
        }

        // Stripe: stay on the page and hand the secret to Stripe Elements.
        $this->orderId = $order->getKey();
        $this->clientSecret = $clientSecret;
    }

    /**
     * One address payload, in the shape `CreateOrder` documents.
     *
     * Billing falls back to the delivery address unless the customer
     * unticked "same as delivery" — the common case by a wide margin, and
     * asking for the same six fields twice is how checkouts get abandoned.
     *
     * @return array<string, mixed>
     */
    private function addressPayload(bool $billing): array
    {
        $useDelivery = ! $billing || $this->billing_same_as_delivery;

        return [
            'delivery_type' => $this->delivery_type,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'country' => mb_strtoupper($this->country),
            'city' => $useDelivery ? $this->city : $this->billing_city,
            'postcode' => $useDelivery ? $this->postcode : $this->billing_postcode,
            'street' => $this->delivery_type === DeliveryType::Address->value
                ? ($useDelivery ? $this->street : $this->billing_street)
                : null,
            'courier_office_code' => $this->delivery_type === DeliveryType::Office->value
                ? ($this->courier_office_code ?: null)
                : null,
            'courier_office_name' => $this->delivery_type === DeliveryType::Office->value
                ? ($this->courier_office_name ?: null)
                : null,
        ];
    }

    #[Computed]
    public function order(): ?Order
    {
        // Scoped, never a bare Order::find(): the visitor may see an order
        // here only if they own it or just placed it in this session — the
        // same entitlement OrderConfirmation enforces, and for the same
        // reason (serial numbers are sequential; a bare lookup enumerates
        // every customer's order). SEC-002.
        if ($this->orderId === null) {
            return null;
        }

        $user = auth()->user();

        if ($user instanceof User) {
            /** @var Order|null $owned */
            $owned = $user->orders()->find($this->orderId);

            if ($owned !== null) {
                return $owned;
            }
        }

        return session(OrderConfirmation::SESSION_KEY) === $this->orderId
            ? Order::find($this->orderId)
            : null;
    }

    public function render(): View
    {
        return view('livewire.checkout.checkout-page', [
            'stripeKey' => config('services.stripe.key'),
        ]);
    }
}
