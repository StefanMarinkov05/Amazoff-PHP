<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\AddressType;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Support\Resolvers\ResolveVariationImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The order-confirmation email — Consumer Rights Directive Art. 8(7)
 * (confirmation of the concluded contract on a durable medium) and GDPR
 * Art. 6(1)(b) contract processing. ADR-0019, `explanation/transactional-email.md`.
 *
 * Sent once, from `CheckoutPage::placeOrder` after the order transaction
 * commits, for both the COD and the card path — the contract is concluded
 * at order placement, not at payment, and the email states the payment
 * status ("payment pending" while a card order is still confirming), the
 * same thing the on-site confirmation page shows.
 *
 * Queued: a slow mail host must not hold the checkout request open. The
 * order id is serialized, not the model, and the view re-reads it — an
 * anonymised order (if erasure somehow raced the queue) renders its
 * anonymised values rather than leaking a snapshot.
 *
 * **No card or payment-token data.** The payment method *label* only.
 */
final class OrderPlaced extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your order {$this->order->serial_number}",
        );
    }

    public function content(): Content
    {
        $order = $this->order->loadMissing([
            'orderItems.productVariation.images',
            'orderItems.product',
            'orderAddresses',
            'payment',
            'carrier',
        ]);

        /** @var array<int, string> $lineImages */
        $lineImages = [];

        foreach ($order->orderItems as $item) {
            $key = $item->getKey();

            if (! is_int($key)) {
                continue;
            }

            $lineImages[$key] = $item->productVariation !== null
                ? ResolveVariationImage::urlOrDefault($item->productVariation)
                : asset(ResolveVariationImage::DEFAULT_PATH);
        }

        return new Content(
            markdown: 'mail.orders.placed',
            with: [
                'order' => $order,
                'delivery' => $order->orderAddresses->firstWhere('type', AddressType::Delivery),
                'billing' => $this->billingAddress($order),
                'lineImages' => $lineImages,
                'trackUrl' => route('orders.track', ['order' => $order->serial_number]),
                'withdrawalUrl' => route('returns.withdrawal-form'),
            ],
        );
    }

    /** The billing address only when it actually differs from delivery. */
    private function billingAddress(Order $order): ?OrderAddress
    {
        $billing = $order->orderAddresses->firstWhere('type', AddressType::Billing);
        $delivery = $order->orderAddresses->firstWhere('type', AddressType::Delivery);

        if ($billing === null || $delivery === null) {
            return $billing;
        }

        $same = $billing->only(['city', 'postcode', 'street', 'first_name', 'last_name'])
            === $delivery->only(['city', 'postcode', 'street', 'first_name', 'last_name']);

        return $same ? null : $billing;
    }
}
