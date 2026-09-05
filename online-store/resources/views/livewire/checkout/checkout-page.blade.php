<div class="mx-auto w-full max-w-5xl px-4 py-12 sm:px-6">

    <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Checkout</h1>

    @if ($this->isEmpty && ! $clientSecret)
        <p class="mt-6 rounded-control border border-ink-200 bg-white px-4 py-6 text-sm text-ink-500">
            Your basket is empty.
            <a href="/catalogue" wire:navigate class="font-medium text-marine-700 underline-offset-4 hover:underline">
                Browse the catalogue
            </a>
        </p>
    @elseif ($clientSecret)

        {{-- ── Payment step ────────────────────────────────────────────────
             The order already exists and holds its stock; this is the card
             confirmation. The client secret authorises confirming *this*
             intent only — it is not a credential and carries no ability to
             read or change anything else. --}}
        <p class="mt-2 text-sm text-ink-500">
            Order <span class="font-medium text-ink-800">{{ $this->order?->serial_number }}</span> is reserved. Enter your card to pay.
        </p>

        <div class="mt-8 max-w-md rounded-control border border-ink-200 bg-white p-5">
            <div id="stripe-payment-element" wire:ignore></div>

            <p id="stripe-error" role="alert" class="mt-3 hidden text-sm text-red-600"></p>

            <button id="stripe-submit" type="button"
                    class="mt-5 w-full rounded-control bg-marine-700 px-4 py-2.5 text-sm font-medium text-white
                           hover:bg-marine-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20
                           disabled:cursor-not-allowed disabled:opacity-60">
                Pay {{ $this->totals['total'] }}
            </button>
        </div>

        {{-- @assets loads once per page and survives Livewire navigation,
             unlike a bare <script> inside a re-rendered component. --}}
        @assets
        <script src="https://js.stripe.com/v3/"></script>
        @endassets

        @script
        <script>
            // Stripe.js is loaded from Stripe's own domain deliberately: PCI
            // guidance is that card fields must be served by Stripe, not by
            // us. The card number never touches this application, which is
            // why `payments` has no card columns to leak.
            const stripe = Stripe(@js($stripeKey));
            const elements = stripe.elements({ clientSecret: @js($clientSecret) });
            elements.create('payment').mount('#stripe-payment-element');

            const button = document.getElementById('stripe-submit');
            const error = document.getElementById('stripe-error');

            button.addEventListener('click', async () => {
                button.disabled = true;
                error.classList.add('hidden');

                const { error: stripeError } = await stripe.confirmPayment({
                    elements,
                    confirmParams: {
                        return_url: @js(route('checkout.confirmation', ['order' => $orderId])),
                    },
                });

                // Only card-entry and immediate-decline errors land here.
                // Anything that succeeds redirects to return_url, and the
                // *authoritative* status change comes from the webhook, not
                // from this redirect — a customer closing the tab mid-redirect
                // must still end up paid.
                if (stripeError) {
                    error.textContent = stripeError.message;
                    error.classList.remove('hidden');
                    button.disabled = false;
                }
            });
        </script>
        @endscript

    @else

        {{-- ── Details step ──────────────────────────────────────────────── --}}
        <form wire:submit="placeOrder" class="mt-8 grid gap-8 lg:grid-cols-[1fr_20rem]" novalidate>

            <div class="space-y-8">

                <section>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-500">Your details</h2>

                    @guest
                        <p class="mt-2 text-sm text-ink-500">
                            Checking out as a guest.
                            <a href="/login" wire:navigate class="font-medium text-marine-700 underline-offset-4 hover:underline">Sign in</a>
                            to save this order to your account.
                        </p>
                    @endguest

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <x-checkout.field name="first_name" label="First name" />
                        <x-checkout.field name="last_name" label="Last name" />
                        <x-checkout.field name="email" label="Email" type="email" />
                        <x-checkout.field name="phone" label="Phone" type="tel" />
                    </div>
                </section>

                <section>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-500">Courier</h2>

                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                        @foreach ($this->carriers as $carrier)
                            <label class="flex items-center gap-2.5 rounded-control border border-ink-200 px-3 py-2.5 text-sm text-ink-700">
                                <input type="radio" wire:model.live="carrier_id" value="{{ $carrier->id }}"
                                       class="h-4 w-4 border-ink-300 text-marine-700 focus:ring-4 focus:ring-marine-600/20">
                                {{ $carrier->name }}
                            </label>
                        @endforeach
                    </div>
                    @error('carrier_id') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </section>

                <section>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-500">Delivery</h2>

                    <div class="mt-4 flex gap-4">
                        @foreach (\App\Enums\DeliveryType::cases() as $type)
                            <label class="flex items-center gap-2 text-sm text-ink-700">
                                <input type="radio" wire:model.live="delivery_type" value="{{ $type->value }}"
                                       class="h-4 w-4 border-ink-300 text-marine-700 focus:ring-4 focus:ring-marine-600/20">
                                {{ $type->getLabel() }}
                            </label>
                        @endforeach
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <x-checkout.field name="city" label="City" />
                        <x-checkout.field name="postcode" label="Postcode" />
                        <x-checkout.field name="country" label="Country code" />

                        @if ($delivery_type === \App\Enums\DeliveryType::Address->value)
                            <x-checkout.field name="street" label="Street and number" class="sm:col-span-2" />
                        @endif
                    </div>

                    @if ($delivery_type === \App\Enums\DeliveryType::Office->value)
                        <div class="mt-4">
                            @if ($courier_office_code !== '')
                                {{-- Picked: the search UI has done its job and only takes up
                                     space and re-invites a second click from here on. --}}
                                <div wire:key="office-picked-card" wire:transition.duration.200ms
                                     class="flex items-start justify-between gap-3 rounded-control border border-marine-200 bg-marine-50 px-3 py-2.5">
                                    <p class="flex items-start gap-1.5 text-sm text-marine-800">
                                        <svg viewBox="0 0 20 20" fill="currentColor" class="mt-0.5 h-4 w-4 shrink-0 text-marine-700"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" /></svg>
                                        <span>
                                            <span class="block font-medium">{{ $courier_office_name }}</span>
                                            <span class="block text-xs text-marine-700">Pickup office selected</span>
                                        </span>
                                    </p>
                                    <button type="button" wire:click="changeOffice"
                                            class="shrink-0 text-sm font-medium text-marine-700 underline-offset-4 hover:underline">
                                        Change
                                    </button>
                                </div>
                            @else
                                <div wire:key="office-search-panel" wire:transition.duration.200ms
                                     x-data x-init="$nextTick(() => $refs.office_search?.focus())">
                                    <label for="office_search" class="block text-sm font-medium text-ink-800">Find an office</label>
                                    <input wire:model.live.debounce.400ms="office_search" wire:key="office-search-input"
                                           x-ref="office_search" id="office_search" type="text"
                                           placeholder="Search by office name or street"
                                           class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                                                  text-sm text-ink-900 placeholder:text-ink-400
                                                  focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/20">

                                    @if ($carrier_id === null)
                                        <p class="mt-2 text-sm text-ink-500">Choose a courier above to see its offices.</p>
                                    @elseif (trim($city) === '')
                                        <p class="mt-2 text-sm text-ink-500">Enter a city to see its offices.</p>
                                    @elseif ($this->courierUnavailable())
                                        <p class="mt-2 text-sm text-amber-600">
                                            {{ $this->carriers->firstWhere('id', $carrier_id)?->name }}'s office lookup isn't reachable right now — try the other courier, or enter a delivery address instead.
                                        </p>
                                    @endif

                                    @if ($carrier_id !== null && trim($city) !== '' && ! $this->courierUnavailable())
                                        <ul wire:loading.class="opacity-50" wire:target="office_search, carrier_id, city, postcode"
                                            class="mt-2 max-h-56 space-y-1 overflow-y-auto rounded-control border border-ink-200 p-1.5 transition-opacity">
                                            @forelse ($this->offices as $office)
                                                <li wire:key="office-{{ $office->code }}">
                                                    <button type="button" wire:click="selectOffice('{{ $office->code }}')"
                                                            wire:loading.class="opacity-50" wire:target="selectOffice('{{ $office->code }}')"
                                                            class="w-full rounded-control px-2.5 py-2 text-left text-sm text-ink-700
                                                                   transition-colors duration-150 hover:bg-ink-50">
                                                        <span class="block font-medium">{{ $office->name }}</span>
                                                        <span class="block text-ink-500">{{ $office->address }}</span>
                                                    </button>
                                                </li>
                                            @empty
                                                <li class="px-2.5 py-2 text-sm text-ink-500">No offices found for this city.</li>
                                            @endforelse
                                        </ul>
                                    @endif
                                </div>
                            @endif

                            @error('courier_office_code') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </section>

                <section>
                    <label class="flex items-center gap-2.5 text-sm text-ink-700">
                        <input type="checkbox" wire:model.live="billing_same_as_delivery"
                               class="h-4 w-4 rounded border-ink-300 text-marine-700 focus:ring-4 focus:ring-marine-600/20">
                        Billing address is the same as delivery
                    </label>

                    @unless ($billing_same_as_delivery)
                        <div wire:key="billing-address-fields" class="mt-4 grid gap-4 sm:grid-cols-2">
                            <x-checkout.field name="billing_city" label="Billing city" />
                            <x-checkout.field name="billing_postcode" label="Billing postcode" />
                            <x-checkout.field name="billing_street" label="Billing street" class="sm:col-span-2" />
                        </div>
                    @endunless
                </section>

                <section>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-500">Payment</h2>

                    <div class="mt-4 space-y-2">
                        @foreach (\App\Enums\PaymentMethod::cases() as $method)
                            <label class="flex items-center gap-2.5 rounded-control border border-ink-200 px-3 py-2.5 text-sm text-ink-700">
                                <input type="radio" wire:model.live="payment_method" value="{{ $method->value }}"
                                       class="h-4 w-4 border-ink-300 text-marine-700 focus:ring-4 focus:ring-marine-600/20">
                                {{ $method->getLabel() }}
                            </label>
                        @endforeach
                    </div>
                </section>

                <section>
                    <label for="customer_note" class="block text-sm font-medium text-ink-800">Order note <span class="text-ink-400">(optional)</span></label>
                    <textarea wire:model="customer_note" id="customer_note" rows="3"
                              class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5 text-sm
                                     focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/20"></textarea>
                    @error('customer_note') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </section>
            </div>

            {{-- ── Summary ─────────────────────────────────────────────────
                 Display only. CreateOrder recalculates every figure from the
                 cart's own rows; nothing here is submitted. §37 #8. --}}
            <aside class="h-fit rounded-control border border-ink-200 bg-white p-5">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-500">Summary</h2>

                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-500">Subtotal</dt>
                        <dd class="text-ink-800">{{ $this->totals['subtotal'] }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-500">of which VAT</dt>
                        <dd class="text-ink-500">{{ $this->totals['vat'] }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-500">
                            Delivery
                            @if ($this->deliveryPrice?->isEstimate)
                                <span class="text-ink-400">(estimated)</span>
                            @endif
                        </dt>
                        <dd class="text-ink-800">
                            {{ $this->deliveryPrice?->amount ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between border-t border-ink-200 pt-2 text-base font-semibold">
                        <dt class="text-ink-900">Total</dt>
                        <dd class="text-ink-900">
                            {{ $this->deliveryPrice === null ? $this->totals['total'] : \App\Support\Money::of($this->totals['total'])->add(\App\Support\Money::of($this->deliveryPrice->amount)) }}
                        </dd>
                    </div>
                </dl>

                <p class="mt-2 text-[0.7rem] text-ink-400">
                    Delivery is calculated once a courier, city and postcode are entered. The order total is always
                    recalculated on the server before you pay.
                </p>

                <button type="submit"
                        class="mt-5 w-full rounded-control bg-marine-700 px-4 py-2.5 text-sm font-medium text-white
                               hover:bg-marine-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20"
                        wire:loading.attr="disabled" wire:target="placeOrder">
                    <span wire:loading.remove wire:target="placeOrder">Place order</span>
                    <span wire:loading wire:target="placeOrder">Placing…</span>
                </button>

                <p class="mt-3 text-center text-[0.7rem] text-ink-400">
                    Stock is reserved when the order is placed.
                </p>
            </aside>
        </form>
    @endif
</div>
