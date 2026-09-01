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
                        @else
                            <x-checkout.field name="courier_office_code" label="Courier office code" />
                            <x-checkout.field name="courier_office_name" label="Courier office name" />
                        @endif
                    </div>
                </section>

                <section>
                    <label class="flex items-center gap-2.5 text-sm text-ink-700">
                        <input type="checkbox" wire:model.live="billing_same_as_delivery"
                               class="h-4 w-4 rounded border-ink-300 text-marine-700 focus:ring-4 focus:ring-marine-600/20">
                        Billing address is the same as delivery
                    </label>

                    @unless ($billing_same_as_delivery)
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
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
                    <div class="flex justify-between border-t border-ink-200 pt-2 text-base font-semibold">
                        <dt class="text-ink-900">Total</dt>
                        <dd class="text-ink-900">{{ $this->totals['total'] }}</dd>
                    </div>
                </dl>

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
