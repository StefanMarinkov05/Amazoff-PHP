<?php

declare(strict_types=1);

namespace App\Livewire\Checkout;

use App\Actions\Cart\RestoreCartFromOrder;
use App\Actions\Order\CreateOrder;
use App\Actions\Order\TransitionOrderStatus;
use App\Actions\Payment\CreateStripeIntent;
use App\Actions\Payment\RecordPayment;
use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\CourierUnavailableException;
use App\Facades\Courier;
use App\Livewire\Concerns\ThrottlesSubmissions;
use App\Mail\OrderPlaced;
use App\Models\Address;
use App\Models\Carrier;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Rules\DeliverableEmailDomain;
use App\Support\CalculateCartTotals;
use App\Support\CalculateDeliveryPrice;
use App\Support\Courier\CourierOffice;
use App\Support\Courier\DeliveryQuote;
use App\Support\Resolvers\ResolveCurrentCart;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
 *
 * ## Carrier and office selection (§12–14)
 *
 * `carriers()` lists the active rows for the radio group; `offices()` calls
 * `Courier::for($carrier)->offices()` for the typed city and is what
 * `selectOffice()` resolves a click against. `courier_office_code`/`_name`
 * are therefore never customer-typed *through the rendered form* — but both
 * are still public properties, independently reachable via `$set()`
 * regardless of what the form does. `placeOrder()` re-resolves the
 * submitted code against `offices()` one more time, and then re-derives
 * `courier_office_name` from *that* resolved office rather than trusting
 * the client's copy of the name at all (SEC-015) — a mismatched or
 * oversized name submitted independently of the code it accompanies is
 * discarded, not merely validated.
 * `docs/explanation/couriers.md` has the full read-path/write-path split.
 */
#[Layout('components.layouts.app')]
class CheckoutPage extends Component
{
    use ThrottlesSubmissions;

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

    /**
     * Deliberately `mixed`, not `?int` — bound `wire:model.live` to the
     * carrier radio group, so hydration assigns whatever the client sends
     * before any of this class's code runs. `updatedCarrierId()` normalises
     * it back to a real id or `null` immediately after; the `rules()` entry
     * (`integer`, `exists`) still guards what `placeOrder()` accepts. Same
     * incident class as `ProductDetails::$quantity` — see `SEC-014`.
     */
    public mixed $carrier_id = null;

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

    public bool $billing_same_as_delivery = false;

    public string $billing_city = '';

    public string $billing_postcode = '';

    public string $billing_street = '';

    public string $customer_note = '';

    /**
     * Which of the signed-in customer's saved addresses is selected in the
     * "use a saved address" picker, or `null` for "enter a new address".
     * Bound `.live` so picking one fills the delivery fields immediately via
     * `updatedSelectedAddressId()`. Not `#[Locked]`: an id the customer does
     * not own is simply ignored by `applySavedAddress()`, which scopes the
     * lookup to `auth()->user()->addresses()`.
     *
     * Deliberately `mixed`, not `?int` — `wire:model.live` hydrates whatever
     * the client sends before `updatedSelectedAddressId()`'s own normalising
     * runs. Same incident class as `$carrier_id` above — see `SEC-014`.
     */
    public mixed $selected_address_id = null;

    /**
     * Set once the order is placed; drives the payment step. Both are
     * written only by placeOrder() on the server and never legitimately come
     * from the client, so both are `#[Locked]` — a public property is
     * re-hydrated from the client on every update, and an unlocked `orderId`
     * let a crafted request point the page at another customer's order.
     * `#[Locked]` blocks the tampering; `order()` below still scopes the
     * lookup, so the id is safe even if it arrives some other way.
     * See `reference/testing/security-testing/sec-001-to-004.md` SEC-002.
     */
    #[Locked]
    public ?int $orderId = null;

    #[Locked]
    public ?string $clientSecret = null;

    /**
     * Set by `resolveOffices()`, for `courierUnavailable()` to read —
     * distinguishes "the carrier answered with zero offices here" from "the
     * carrier could not be reached at all" (Speedy today: no sandbox exists
     * yet, so `SPEEDY_API_URL` stays blank and every call fails instantly).
     * Both cases would otherwise return an empty office list, so the UI
     * needs a second signal to avoid telling the customer a real city has no
     * offices.
     */
    private bool $courierUnavailable = false;

    /**
     * Set once per request by `resolveOffices()`, the first time either
     * `offices()` or `courierUnavailable()` is read — Blade reads both, in
     * that order, on every render (the "unavailable" message and the list
     * are alternatives), and each is a real courier call if evaluated raw.
     * Memoising here means the live lookup runs at most once per render
     * rather than once per reader.
     *
     * @var Collection<int, CourierOffice>|null
     */
    private ?Collection $resolvedOffices = null;

    /**
     * The last successfully fetched office list for the *current*
     * carrier/city/postcode, kept as plain arrays — Livewire's property
     * hydration has no synthesizer for a bare readonly DTO like
     * `CourierOffice`, the same reason `CachedCourierGateway` caches arrays
     * rather than objects (`docs/how-to/troubleshooting/infra-and-environment.md`,
     * "A cached object comes back as `__PHP_Incomplete_Class`").
     *
     * Once a real fetch has succeeded, a *later* render's fetch failing —
     * Econt's demo host is a shared public environment, and every field on
     * this page re-renders the whole component, so any of them can be the
     * one whose request happens to land during a slow moment — no longer
     * blanks a list the customer is already looking at. `updated()` clears
     * this the moment anything that changes what "this city's offices"
     * means changes, so a stale list is never shown for the wrong city.
     *
     * @var list<array{code: string, name: string, address: string, city: string, postcode: string, maxWeightGrams: ?int, supportsCod: bool}>
     */
    public array $lastKnownOffices = [];

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

            // If the customer has a default shipping address, start with it
            // selected and its fields filled — the same "prefill, don't
            // commit" rule: they can switch to "enter a new address" or edit
            // any field, and only what they submit is snapshotted.
            $default = $user->addresses()
                ->where('is_default_shipping', true)
                ->first()
                ?? $user->addresses()->first();

            if ($default instanceof Address) {
                $key = $default->getKey();
                $this->selected_address_id = is_int($key) ? $key : null;
                $this->applySavedAddress($default);
            }
        }
    }

    /**
     * The signed-in customer's saved addresses, for the checkout picker.
     * Empty for a guest, so the picker is not rendered at all.
     *
     * @return EloquentCollection<int, Address>
     */
    #[Computed]
    public function savedAddresses(): EloquentCollection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            /** @var EloquentCollection<int, Address> */
            return new EloquentCollection;
        }

        return $user->addresses()
            ->orderByDesc('is_default_shipping')
            ->orderBy('label')
            ->get();
    }

    /**
     * Fills the delivery fields from the address the customer picked. Scoped
     * to their own addresses — an id for someone else's row resolves to
     * `null` and nothing changes.
     */
    public function updatedSelectedAddressId(mixed $value): void
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            $this->selected_address_id = null;

            return;
        }

        $user = auth()->user();

        $address = $user instanceof User
            ? $user->addresses()->find((int) $value)
            : null;

        if ($address instanceof Address) {
            $this->selected_address_id = (int) $value;
            $this->applySavedAddress($address);

            return;
        }

        // Not one of this customer's addresses — treat as "new address".
        $this->selected_address_id = null;
    }

    private function applySavedAddress(Address $address): void
    {
        // A saved address is a street address, never a courier office.
        $this->delivery_type = DeliveryType::Address->value;
        $this->first_name = $address->first_name;
        $this->last_name = $address->last_name;
        $this->phone = $address->phone;
        $this->country = mb_strtoupper($address->country);
        $this->city = $address->city;
        $this->postcode = $address->postcode;
        $this->street = $address->street;

        // The city/postcode changed, so any office selection and delivery
        // quote no longer apply — same reset updated() performs.
        $this->courier_office_code = '';
        $this->courier_office_name = '';
        $this->lastKnownOffices = [];
        unset($this->offices, $this->deliveryPrice);
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            // A domain check on top of the syntax one, here and nowhere
            // else in the app. This is the one form where a wrong address
            // costs the customer the only record of a contract they paid
            // for: the card path's CRD Art. 8(7) confirmation is sent once,
            // when payment lands (ADR-0022), with no second chance to
            // correct it. Catches the common typo class (`gmial.com`) that
            // `rfc` alone accepts happily.
            //
            // `DeliverableEmailDomain` rather than Laravel's own
            // `email:rfc,dns`, which does the same lookup with no way to
            // switch it off: every fixture here is `@example.test`, and
            // `.test` never resolves by design, so the built-in rule turned
            // 21 unrelated checkout tests red. See the rule's docblock.
            //
            // The cost is a live MX lookup on submit. Accepted here because
            // checkout is low-volume and high-value; the same rule on login
            // or the newsletter would put a network call on a surface an
            // attacker can loop.
            'email' => ['required', 'string', 'email:rfc', 'max:100', new DeliverableEmailDomain],
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
            // courier_office_name carries no rule here: it is not trusted as
            // submitted regardless of what a rule would allow through.
            // selectOffice() sets it alongside the code, from an office
            // offices() itself returned, but a public property is still
            // independently client-settable via $set() — placeOrder()
            // re-derives it from the office the code resolves to rather than
            // validating the client's copy at all (SEC-015).
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
        $all = $this->resolveOffices();

        if (trim($this->office_search) === '') {
            return $all;
        }

        $term = mb_strtolower($this->office_search);

        return $all->filter(
            fn (CourierOffice $office): bool => str_contains(mb_strtolower($office->name), $term)
                || str_contains(mb_strtolower($office->address), $term),
        )->values();
    }

    public function courierUnavailable(): bool
    {
        $this->resolveOffices();

        return $this->courierUnavailable;
    }

    /**
     * The one real courier call per render — `offices()` and
     * `courierUnavailable()` both delegate here instead of each running
     * their own, so they can never observe two different outcomes of what
     * is meant to be the same lookup.
     *
     * A per-IP throttle sits ahead of the real call: office lookups reach a
     * live courier API on every distinct city typed (`CachedCourierGateway`
     * only saves repeat calls for the *same* city), so an unauthenticated
     * visitor cycling through city names can otherwise drive unbounded
     * traffic at Econt/Speedy. A trip is treated exactly like the courier
     * itself being unavailable — same fallback to `lastKnownOffices`, same
     * amber message — rather than surfaced as a form error, since nothing
     * about this failure is the customer's typing to correct.
     *
     * @return Collection<int, CourierOffice>
     */
    private function resolveOffices(): Collection
    {
        if ($this->resolvedOffices !== null) {
            return $this->resolvedOffices;
        }

        $this->courierUnavailable = false;

        if ($this->delivery_type !== DeliveryType::Office->value || $this->carrier_id === null || trim($this->city) === '') {
            return $this->resolvedOffices = collect();
        }

        $carrier = $this->carriers()->firstWhere('id', $this->carrier_id);

        if (! $carrier instanceof Carrier) {
            return $this->resolvedOffices = collect();
        }

        try {
            $this->throttleSubmission('courier-offices|'.$this->requestIp(), 'city', maxAttempts: 30, decaySeconds: 60);

            $offices = Courier::for($carrier)->offices($this->city, $this->postcode ?: null);
        } catch (ValidationException|CourierUnavailableException) {
            if ($this->lastKnownOffices !== []) {
                return $this->resolvedOffices = collect($this->lastKnownOffices)
                    ->map(fn (array $row): CourierOffice => new CourierOffice(...$row));
            }

            $this->courierUnavailable = true;

            return $this->resolvedOffices = collect();
        }

        $this->lastKnownOffices = array_values($offices->map(fn (CourierOffice $office): array => (array) $office)->all());

        return $this->resolvedOffices = $offices;
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

    /** Reopens the picker — the office list itself is still cached, so this is free. */
    public function changeOffice(): void
    {
        $this->courier_office_code = '';
        $this->courier_office_name = '';
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
        if ($property === 'carrier_id') {
            // $carrier_id is `mixed` (see its own docblock) — normalise the
            // raw client value back to a real id or null, same shape
            // ProductDetails::mount() uses for $variationId.
            $this->carrier_id = is_numeric($this->carrier_id) && (int) $this->carrier_id == $this->carrier_id
                ? (int) $this->carrier_id
                : null;
        }

        if (in_array($property, ['carrier_id', 'city', 'postcode', 'delivery_type'], true)) {
            $this->courier_office_code = '';
            $this->courier_office_name = '';
            $this->lastKnownOffices = [];
            unset($this->offices, $this->deliveryPrice);
        }

        if (in_array($property, ['payment_method', 'billing_same_as_delivery'], true)) {
            unset($this->deliveryPrice);
        }

        // Hand-editing any delivery field means the customer is no longer
        // using a saved address verbatim — drop the selection so the picker
        // reads "New address" rather than implying an unedited saved one.
        if (in_array($property, ['first_name', 'last_name', 'phone', 'country', 'city', 'postcode', 'street', 'delivery_type'], true)) {
            $this->selected_address_id = null;
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
        if ($this->delivery_type === DeliveryType::Office->value) {
            $office = $this->offices()->firstWhere('code', $validated['courier_office_code']);

            if ($office === null) {
                $this->addError('courier_office_code', 'Please choose a courier office from the list.');

                return;
            }

            // $courier_office_name is a public property the client can set
            // via $set() independently of what selectOffice() wrote — the
            // code above is re-resolved against offices(); the name gets the
            // same treatment here, from the office that resolution just
            // proved is real, rather than trusting whatever the property
            // currently holds (SEC-015).
            $this->courier_office_name = $office->name;
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

                // No online gateway for this method — CLAUDE.md, "Scope".
                // There is no intent to create and nothing for the browser
                // to confirm. Asked of the enum so a third method declares
                // its own answer rather than inheriting one from a negation.
                if (! $method->requiresOnlinePayment()) {
                    return [$order, null];
                }

                $createIntent = app(CreateStripeIntent::class);
                $payment = $createIntent->handle($payment, $actor);

                // The card order now has somewhere to be paid, so it moves
                // off New. This is what makes "an unpaid card order" a state
                // the database can be queried for — ExpireUnpaidOrders sweeps
                // exactly this status, and before this transition existed an
                // abandoned card checkout was indistinguishable from a COD
                // order waiting for staff. ADR-0022 decision 1.
                //
                // Null actor: the customer holds no order permissions, and
                // TransitionOrderStatus skips the policy check for a null
                // actor by design (ADR-0007).
                $order = app(TransitionOrderStatus::class)->handle(
                    $order,
                    OrderStatus::AwaitingPayment,
                    null,
                );

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

        // The order-confirmation email — Consumer Rights Directive Art. 8(7),
        // the confirmation of the concluded contract on a durable medium
        // (ADR-0019). Queued, so a slow mail host cannot hold checkout open;
        // after the transaction has committed, so a rolled-back order never
        // triggers one.
        //
        // Sent once for every payment method; what the enum decides is
        // *when*. A method whose contract concludes at placement (cash on
        // delivery) is confirmed here; one that concludes when money
        // arrives (card) is confirmed by SendOrderPlacedConfirmation on
        // OrderStatusChanged instead. Sending here for a card order was the
        // abandonment bug — it told a customer who closed the Stripe tab
        // that they had bought something. ADR-0022 decision 5.
        //
        // Asked of the enum rather than written as `!== Stripe`: a third
        // method (a deposit, a wallet) must state its own answer instead of
        // being swept into whichever side the negation happens to put it
        // on. See PaymentMethod::concludesContractAtPlacement().
        if ($method->concludesContractAtPlacement()) {
            Mail::to($order->email)->queue(new OrderPlaced($order));
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
        $orderKey = $order->getKey();

        if (! is_int($orderKey)) {
            throw new InvalidArgumentException('Order::getKey() returned a non-integer value.');
        }

        $this->orderId = $orderKey;
        $this->clientSecret = $clientSecret;
    }

    /**
     * "Cancel" on the address/details step — before any order exists.
     *
     * Nothing has been written at this point: `placeOrder` is the only
     * thing that creates an order, and it has not run. So this is purely a
     * navigation away, and the cart is untouched and still current
     * (`CreateOrder` has not consumed it).
     *
     * A method rather than a plain link so the two Cancels read the same in
     * the template, and so this one has somewhere to grow if the details
     * step ever starts writing something of its own.
     */
    public function cancelCheckout(): void
    {
        $this->redirectRoute('cart', navigate: true);
    }

    /**
     * "Cancel" at the Stripe payment step — after the order exists and is
     * holding stock.
     *
     * This is the deliberate version of the abandonment ADR-0022 sweeps up
     * after: the customer says so instead of closing the tab, and gets an
     * immediate release rather than waiting out the TTL.
     *
     * Three things have to happen, in this order:
     *
     * 1. **Cancel the order**, which releases every line's reservation —
     *    `TransitionOrderStatus` owns that effect (ADR-0011) and this
     *    composes no `ReleaseStock` call of its own.
     * 2. **Rebuild the basket.** `CreateOrder` consumed the cart and
     *    `ResolveCurrentCart` will not return a spent one, so the customer
     *    would otherwise land on an empty cart having lost everything.
     *    `RestoreCartFromOrder`'s docblock has the full reasoning for why
     *    it opens a fresh cart rather than reviving the original.
     * 3. **Drop the session claim**, so the cancelled order's confirmation
     *    page is no longer reachable from this session. Nothing sensitive
     *    leaks if it stays — the visitor placed the order — but a
     *    confirmation page for an order that no longer stands is a
     *    misleading thing to leave behind.
     *
     * Scoped through `order()`, never a bare `Order::find()`: `$orderId` is
     * `#[Locked]`, but the entitlement check is what actually stops this
     * cancelling somebody else's order (SEC-002).
     */
    public function cancelPayment(): void
    {
        $order = $this->order();

        if ($order === null) {
            // No order to cancel — a stale page, or a crafted call. Send
            // them to the cart rather than erroring at them.
            $this->redirectRoute('cart', navigate: true);

            return;
        }

        // Only from AwaitingPayment. A paid order (the webhook landed while
        // the customer was reaching for Cancel) must not be cancelled from
        // a button — that is a refund, which is staff work.
        if ($order->status !== OrderStatus::AwaitingPayment) {
            $this->redirectRoute('checkout.confirmation', ['order' => $order->getKey()], navigate: true);

            return;
        }

        // Null actor: the customer holds no cancel_order permission, and
        // cancelling their own unpaid order takes nothing from anybody.
        // ADR-0007's null-actor path, same as the sweep's.
        app(TransitionOrderStatus::class)->handle(
            $order,
            OrderStatus::Cancelled,
            null,
            'Cancelled by the customer at the payment step.',
        );

        $user = auth()->user();
        $userKey = $user instanceof User ? $user->getKey() : null;

        app(RestoreCartFromOrder::class)->handle(
            $order,
            is_int($userKey) ? $userKey : null,
            session()->getId(),
        );

        session()->forget(OrderConfirmation::SESSION_KEY);

        $this->orderId = null;
        $this->clientSecret = null;

        session()->flash('success', 'Your order was cancelled and your basket restored.');

        $this->redirectRoute('cart', navigate: true);
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
