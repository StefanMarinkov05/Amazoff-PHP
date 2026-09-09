@php
    use App\Enums\DeliveryType;
    use App\Enums\PaymentStatus;
    $currency = $order->currency;
    $addr = fn ($a) => $a === null ? null : ($a->delivery_type === DeliveryType::Office
        ? trim("{$a->courier_office_name}, {$a->city} {$a->postcode}, {$a->country}")
        : trim("{$a->street}, {$a->city} {$a->postcode}, {$a->country}"));
@endphp

<x-mail::message>
# Thank you for your order

Hi {{ $order->first_name }},

We have received your order **{{ $order->serial_number }}**, placed on
{{ $order->created_at?->format('j M Y, H:i') }}. This email is your
confirmation of the contract.

@if ($order->payment)
@if ($order->payment->status === PaymentStatus::Paid)
Payment: **{{ $order->payment->method->getLabel() }} — paid.**
@elseif ($order->payment->method->value === 'cash_on_delivery')
Payment: **cash on delivery** — you pay the courier when the parcel arrives.
@else
Payment: **{{ $order->payment->method->getLabel() }} — confirmation pending.**
We will email you again once your bank confirms it. Your order is being
prepared in the meantime.
@endif
@endif

## What you ordered

<x-mail::table>
| Item | Qty | Unit price | Line total |
|:-----|:---:|-----------:|-----------:|
@foreach ($order->orderItems as $item)
| **{{ $item->product_name }}**@if ($item->variation_name) <br><small>{{ $item->variation_name }}</small>@endif <br><small>SKU {{ $item->product_sku }}</small> | {{ $item->quantity }} | {{ $item->unit_price }} {{ $currency->value }} | {{ $item->line_total }} {{ $currency->value }} |
@endforeach
</x-mail::table>

@foreach ($order->orderItems as $item)
<img src="{{ $lineImages[$item->getKey()] ?? '' }}" alt="{{ $item->product_name }}" width="72" height="72" style="border-radius:6px;border:1px solid #e5e7eb;margin:0 8px 8px 0;object-fit:cover;">
@endforeach

<x-mail::table>
|  |  |
|:--|--:|
| Subtotal | {{ $order->subtotal_amount }} {{ $currency->value }} |
@if ((float) $order->discount_amount > 0)
| Discount | −{{ $order->discount_amount }} {{ $currency->value }} |
@endif
| Delivery | {{ $order->shipping_amount }} {{ $currency->value }} |
| VAT (included) | {{ $order->vat_amount }} {{ $currency->value }} |
| **Total** | **{{ $order->total_amount }} {{ $currency->value }}** |
</x-mail::table>

## Delivery

@if ($addr($delivery))
{{ $order->first_name }} {{ $order->last_name }}
{{ $addr($delivery) }}
@if ($order->carrier)
Carrier: {{ $order->carrier->name }}
@endif
@else
Delivery address on file with the order.
@endif

@if ($billing)
## Billing address

{{ $billing->first_name }} {{ $billing->last_name }}
{{ $addr($billing) }}
@endif

<x-mail::button :url="$trackUrl">
Track your order
</x-mail::button>

## Your right to withdraw

You may withdraw from this purchase within **14 days** of receiving the
goods, without giving a reason. Use the
[model withdrawal form]({{ $withdrawalUrl }}), or request a return from your
order page once the parcel has arrived. Full terms are on the website.

Thanks,<br>
{{ config('app.name') }}

<x-slot:subcopy>
This email was sent to {{ $order->email }} because an order was placed with
that address. It contains no payment-card details.
</x-slot:subcopy>
</x-mail::message>
