<?php

declare(strict_types=1);

namespace App\Livewire\Checkout;

use App\Actions\Order\CreateOrder;
use App\Actions\Payment\CreateStripeIntent;
use App\Actions\Payment\RecordPayment;
use App\Enums\DeliveryType;
use App\Enums\PaymentMethod;
use App\Exceptions\CourierUnavailableException;
use App\Facades\Courier;
use App\Models\Carrier;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Support\CalculateCartTotals;
use App\Support\CalculateDeliveryPrice;
use App\Support\Courier\CourierOffice;
use App\Support\Courier\DeliveryQuote;
use App\Support\ResolveCurrentCart;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
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
 *
 * ## Carrier and office selection (§12–14)
 *
 * `carriers()` lists the active rows for the radio group; `offices()` calls
 * `Courier::for($carrier)->offices()` for the typed city and is what
 * `selectOffice()` resolves a click against. `courier_office_code`/`_name`
 * are therefore never customer-typed text — `placeOrder()` re-resolves the
 * submitted code against `offices()` one more time before trusting it,
 * because the browser can still submit any string as the property value.
 * `docs/explanation/couriers.md` has the full read-path/write-path split.
 */
#[Layout('components.layouts.app')]
class CheckoutPage extends Component
{
    public string $email = '';

    public string $phone = '';

    public string $first_name = '';

    public string $last_name = '';

    public string $payment_method = PaymentMethod::CashOnDelivery->value;

    public string $delivery_type = DeliveryType::Address->value;

    public ?int $carrier_id = null;

    public string $country = 'BG';

    public string $city = '';

    public string $postcode = '';

    public string $street = '';

    /** Filters `offices()` client-side; never sent to a courier per keystroke. */
    public string $office_search = '';

    /**
     * Set only by `selectOffice()`, from an office `offices()` itself
     * returned — never typed by the customer. See its own docblock for why.
     */
    public string $courier_office_code = '';

    public string $courier_office_name = '';

    public bool $billing_same_as_delivery = true;

    public string $billing_city = '';

    public string $billing_postcode = '';

    public string $billing_street = '';

    public string $customer_note = '';

    /** Set once the order is placed; drives the payment step. */
    public ?int $orderId = null;

    public ?string $clientSecret = null;

    /**
     * Set by `offices()` on every call, for `courierUnavailable()` to read —
     * distinguishes "the carrier answered with zero offices here" from "the
     * carrier could not be reached at all" (Speedy today: no sandbox exists
     * yet, so `SPEEDY_API_URL` stays blank and every call fails instantly).
     * Both cases return an empty `offices()` collection, so the UI needs a
     * second signal to avoid telling the customer a real city has no offices.
     */
    private bool $courierUnavailable = false;

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
            'carrier_id' => ['required', 'integer', Rule::exists('carriers', 'id')->where('is_active', true)],
            'country' => ['required', 'string', 'size:2'],
            'city' => ['required', 'string', 'max:50'],
            'postcode' => ['required', 'string', 'max:20'],
            // Exactly one of the two address shapes, decided by delivery_type
            // rather than by which fields happen to be filled.
            'street' => ['nullable', 'required_if:delivery_type,address', 'string', 'max:150'],
            // courier_office_name carries no rule: it is never customer
            // input. selectOffice() sets it alongside the code, from an
            // office offices() itself returned, and placeOrder() re-resolves
            // both against that same list before trusting either.
            'courier_office_code' => ['nullable', 'required_if:delivery_type,office', 'string', 'max:50'],
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

    /** @return EloquentCollection<int, Carrier> */
    #[Computed]
    public function carriers(): EloquentCollection
    {
        return Carrier::query()->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * The selected carrier's offices for the typed city, cached a day at a
     * time by `CachedCourierGateway` — `office_search` then filters this
     * result in PHP rather than firing a request per keystroke.
     *
     * @return Collection<int, CourierOffice>
     */
    #[Computed]
    public function offices(): Collection
    {
        $this->courierUnavailable = false;

        if ($this->delivery_type !== DeliveryType::Office->value || $this->carrier_id === null || trim($this->city) === '') {
            return collect();
        }

        $carrier = $this->carriers()->firstWhere('id', $this->carrier_id);

        if (! $carrier instanceof Carrier) {
            return collect();
        }

        try {
            $offices = Courier::for($carrier)->offices($this->city, $this->postcode ?: null);
        } catch (CourierUnavailableException) {
            $this->courierUnavailable = true;

            return collect();
        }

        if (trim($this->office_search) === '') {
            return $offices;
        }

        $term = mb_strtolower($this->office_search);

        return $offices->filter(
            fn (CourierOffice $office): bool => str_contains(mb_strtolower($office->name), $term)
                || str_contains(mb_strtolower($office->address), $term),
        )->values();
    }

    /** Reads the flag `offices()` sets — call after `offices()` this render, not before. */
    public function courierUnavailable(): bool
    {
        $this->offices();

        return $this->courierUnavailable;
    }

    /**
     * Display only — `CreateOrder`/`CalculateDeliveryPrice` resolve the
     * charged figure again from the order's own rows, per this class's
     * "total is never taken from the browser" rule.
     */
    #[Computed]
    public function deliveryPrice(): ?DeliveryQuote
    {
        if ($this->isEmpty() || $this->carrier_id === null || trim($this->city) === '' || trim($this->postcode) === '') {
            return null;
        }

        if ($this->delivery_type === DeliveryType::Office->value && $this->courier_office_code === '') {
            return null;
        }

        $carrier = $this->carriers()->firstWhere('id', $this->carrier_id);

        if (! $carrier instanceof Carrier) {
            return null;
        }

        return CalculateDeliveryPrice::forCart(
            $this->cart(),
            $carrier,
            PaymentMethod::from($this->payment_method),
            $this->addressPayload(billing: false),
        );
    }

    /** Sets both the code and the name from an office `offices()` itself returned. */
    public function selectOffice(string $code): void
    {
        $office = $this->offices()->firstWhere('code', $code);

        if ($office === null) {
            return;
        }

        $this->courier_office_code = $office->code;
        $this->courier_office_name = $office->name;
        unset($this->deliveryPrice);
    }

    /**
     * An Econt office submitted while Speedy is selected — or an office from
     * a city the customer has since changed — is a shipment that would fail
     * at label time, in the warehouse, days later. Clearing the selection on
     * every input that changes what a "valid office" means is what prevents
     * it from ever being submitted in the first place.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['carrier_id', 'city', 'postcode', 'delivery_type'], true)) {
            $this->courier_office_code = '';
            $this->courier_office_name = '';
            unset($this->offices, $this->deliveryPrice);
        }

        if (in_array($property, ['payment_method', 'billing_same_as_delivery'], true)) {
            unset($this->deliveryPrice);
        }
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

        /** @var Carrier $carrier */
        $carrier = Carrier::query()->findOrFail($validated['carrier_id']);

        // The browser can submit any courier_office_code; only one resolved
        // from this carrier's own office list, for this city, is trusted —
        // the same principle CLAUDE.md applies to a submitted total. A stale
        // code (the customer changed carrier or city after picking one, or
        // never picked one at all) fails here rather than at label time.
        if ($this->delivery_type === DeliveryType::Office->value
            && $this->offices()->firstWhere('code', $validated['courier_office_code']) === null) {
            $this->addError('courier_office_code', 'Please choose a courier office from the list.');

            return;
        }

        try {
            [$order, $clientSecret] = DB::transaction(function () use ($validated, $actor, $method, $carrier): array {
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
                    $carrier,
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
        return $this->orderId === null ? null : Order::find($this->orderId);
    }

    public function render(): View
    {
        return view('livewire.checkout.checkout-page', [
            'stripeKey' => config('services.stripe.key'),
        ]);
    }
}
