<?php

declare(strict_types=1);

namespace App\Livewire\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Public order tracking — the page CLAUDE.md's security rules already assumed
 * existed before it was built.
 *
 * ## Why both fields are required
 *
 * "Public order tracking requires order number **and** email — sequential
 * numbers alone enumerate every customer's address" (CLAUDE.md). This is the
 * same enumeration `OrderConfirmation`'s docblock describes, on the one route
 * that is deliberately open to anyone: a guest who placed an order has no
 * session left a week later and no account to sign into, so the serial alone
 * cannot be the key. The email is the shared secret.
 *
 * Both are matched in a single `where`, so a wrong email and a wrong serial
 * are indistinguishable to the caller. Splitting them — looking the order up
 * by serial, then comparing the email — would turn this into an oracle for
 * which serials exist, which is exactly what the rule exists to prevent.
 *
 * ## Why the failure message is deliberately vague
 *
 * One message for "no such order", "wrong email", and "right order, wrong
 * email". Naming which half failed would confirm a serial is real, and this
 * is the account-enumeration reasoning `Login` already applies to
 * credentials, one aggregate over.
 *
 * ## Rate limiting
 *
 * Serial numbers are sequential and an email is guessable for a targeted
 * victim, so an unthrottled form here is an offline-speed oracle at HTTP
 * speed. Keyed on IP alone rather than IP+serial: keying on the serial would
 * let an attacker walk the range at 5 attempts *each*, which is not a limit.
 *
 * ## What it deliberately does not show
 *
 * No addresses, no phone number, no line items, no customer name. A tracking
 * page answers "where is my order" — status, dates, and a total. The order
 * *contents* are behind ownership (`OrderConfirmation` and the account order
 * list), because email possession is a weaker claim than an authenticated
 * session and the data behind it should be correspondingly narrower.
 */
#[Layout('components.layouts.app')]
class TrackOrder extends Component
{
    public string $serial_number = '';

    public string $email = '';

    /**
     * Locked: the id of an order this visitor has already proven entitlement
     * to in this session. `#[Locked]` because it is an identifier feeding a
     * lookup — the standing rule from SEC-001/SEC-002, which both came from a
     * client-writable property a query downstream trusted.
     */
    #[Locked]
    public ?int $foundOrderId = null;

    /** @return array<string, string> */
    protected function rules(): array
    {
        return [
            'serial_number' => 'required|string|max:50',
            'email' => 'required|string|email:rfc|max:100',
        ];
    }

    public function track(): void
    {
        $this->validate();
        $this->ensureIsNotRateLimited();

        // Both halves in one query. See the class docblock: a two-step lookup
        // would answer "does this serial exist" separately from "is this the
        // right email", which is the oracle this page must not be.
        $order = Order::query()
            ->where('serial_number', trim($this->serial_number))
            ->where('email', trim($this->email))
            ->first();

        if ($order === null) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'serial_number' => 'We could not find an order matching that number and email address.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        $this->foundOrderId = $order->getKey();
    }

    /**
     * The order this visitor proved entitlement to, re-read on every render.
     *
     * Re-queried rather than held as a model, so a status change between the
     * lookup and a later re-render is reflected — and so the entitlement is a
     * property of `$foundOrderId` being set by `track()`, not of a model
     * hydrated once. `#[Locked]` on that property is what makes trusting it
     * here sound.
     */
    public function trackedOrder(): ?Order
    {
        if ($this->foundOrderId === null) {
            return null;
        }

        return Order::query()
            ->with('shipment')
            ->find($this->foundOrderId);
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        throw ValidationException::withMessages([
            'serial_number' => 'Too many attempts. Please try again in a minute.',
        ]);
    }

    private function throttleKey(): string
    {
        return 'track-order|'.Str::transliterate(request()->ip() ?? 'unknown');
    }

    public function render(): View
    {
        return view('livewire.orders.track-order', [
            'order' => $this->trackedOrder(),
        ]);
    }
}
