<?php

declare(strict_types=1);

namespace App\Actions\Gdpr;

use App\Models\Address;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\ProductReview;
use App\Models\ReturnItem;
use App\Models\User;
use App\Models\WishlistItem;
use DateTimeInterface;

/**
 * GDPR Art. 15 / Art. 20 — a copy of everything the shop holds about one
 * customer, as a structured, machine-readable document (ADR-0019).
 *
 * The mirror image of `EraseCustomer`: it walks the same tables, but reads
 * rather than rewrites. Includes rows keyed by the customer's email as well
 * as by `user_id`, so a subscription or contact message made before the
 * account existed is in the export too.
 *
 * `coupon_redemptions` is included as the *fact* of a redemption (which
 * coupon, when) but not the `email_hash` — the hash is derived data the
 * customer cannot verify, and disclosing it discloses the pepper's effect,
 * not their own data.
 *
 * Synchronous and read-only: single-customer scope, and the self-service
 * flow streams the result straight back as a download.
 */
final class ExportCustomerData
{
    /** @return array<string, mixed> */
    public function handle(User $user): array
    {
        $user->loadMissing([
            'addresses',
            'wishlistItems.product:id,name',
            'orders.orderItems',
            'orders.orderAddresses',
            'orders.payment',
            'orders.returns.returnItems.orderItem:id,product_name',
            'orders.couponRedemptions.coupon:id,code',
            'productReviews.product:id,name',
        ]);

        return [
            'exported_at' => $this->iso(now()),
            'notice' => 'This document contains all personal data Amazoff holds about your account. '
                .'Anonymised past orders are retained as invoices under Bulgarian accounting law.',
            'account' => [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'registered_at' => $this->iso($user->created_at),
                'email_verified_at' => $this->iso($user->email_verified_at),
            ],
            'addresses' => $user->addresses
                ->map(fn (Address $a): array => [
                    'label' => $a->label,
                    'first_name' => $a->first_name,
                    'last_name' => $a->last_name,
                    'phone' => $a->phone,
                    'country' => $a->country,
                    'city' => $a->city,
                    'postcode' => $a->postcode,
                    'street' => $a->street,
                    'is_default_billing' => $a->is_default_billing,
                    'is_default_shipping' => $a->is_default_shipping,
                ])->all(),
            'wishlist' => $user->wishlistItems
                ->map(fn (WishlistItem $w): array => [
                    'product' => $w->product?->name,
                    'added_at' => $this->iso($w->created_at),
                ])->all(),
            'orders' => $user->orders
                ->map(fn (Order $order): array => $this->order($order))->all(),
            'reviews' => $user->productReviews
                ->map(fn (ProductReview $r): array => [
                    'product' => $r->product?->name,
                    'rating' => $r->rating,
                    'body' => $r->body,
                    'submitted_at' => $this->iso($r->created_at),
                    'approved' => $r->approved,
                ])->all(),
            'newsletter' => NewsletterSubscriber::query()
                ->where('user_id', $user->getKey())
                ->orWhere('email', $user->email)
                ->get()
                ->map(fn (NewsletterSubscriber $n): array => [
                    'email' => $n->email,
                    'status' => $n->status->value,
                    'subscribed_at' => $this->iso($n->subscribed_at),
                ])->all(),
            'contact_messages' => ContactMessage::query()
                ->where('user_id', $user->getKey())
                ->orWhere('email', $user->email)
                ->get()
                ->map(fn (ContactMessage $m): array => [
                    'subject' => $m->subject,
                    'message' => $m->message,
                    'sent_at' => $this->iso($m->created_at),
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function order(Order $order): array
    {
        return [
            'number' => $order->serial_number,
            'placed_at' => $this->iso($order->created_at),
            'status' => $order->status->value,
            'anonymised' => $order->anonymized_at !== null,
            'total' => $order->total_amount,
            'currency' => $order->currency->value,
            'items' => $order->orderItems
                ->map(fn (OrderItem $i): array => [
                    'product' => $i->product_name,
                    'variation' => $i->variation_name,
                    'sku' => $i->product_sku,
                    'quantity' => $i->quantity,
                    'unit_price' => $i->unit_price,
                    'line_total' => $i->line_total,
                ])->all(),
            'addresses' => $order->orderAddresses
                ->map(fn (OrderAddress $a): array => [
                    'type' => $a->type->value,
                    'first_name' => $a->first_name,
                    'last_name' => $a->last_name,
                    'phone' => $a->phone,
                    'country' => $a->country,
                    'city' => $a->city,
                    'postcode' => $a->postcode,
                    'street' => $a->street,
                    'courier_office' => $a->courier_office_name,
                ])->all(),
            'payment' => $order->payment === null ? null : [
                'method' => $order->payment->method->value,
                'status' => $order->payment->status->value,
                'paid_at' => $this->iso($order->payment->paid_at),
            ],
            'coupons_used' => $order->couponRedemptions
                ->map(fn ($r): ?string => $r->coupon?->code)
                ->filter()
                ->values()
                ->all(),
            'returns' => $order->returns
                ->map(fn (OrderReturn $return): array => [
                    'status' => $return->status->value,
                    'reason' => $return->reason,
                    'resolution_note' => $return->resolution_note,
                    'refunded_amount' => $return->refunded_amount,
                    'requested_at' => $this->iso($return->requested_at),
                    'resolved_at' => $this->iso($return->resolved_at),
                    'items' => $return->returnItems
                        ->map(fn (ReturnItem $item): array => [
                            'product' => $item->orderItem?->product_name,
                            'quantity' => $item->quantity,
                        ])->all(),
                ])->all(),
        ];
    }

    private function iso(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof DateTimeInterface ? $value : new \DateTimeImmutable($value))
            ->format(DateTimeInterface::ATOM);
    }
}
