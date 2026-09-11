{{--
    A money amount with its currency symbol.

    Every price on the storefront used a bare number_format() inline, which
    meant the symbol was easy to forget (the account order pages did) and
    the currency was hard-coded even though orders.currency and
    payments.currency snapshot a real App\Enums\Currency. This component
    takes the amount and the currency and renders one consistent string.

    amount is anything numeric: a decimal:2 cast value, a Money, a string.
    currency is an App\Enums\Currency (or its backed string); it defaults to
    the catalogue currency, for the catalogue pages that have no order row
    to read one from.

    Usage:
      x-money :amount="$order->total_amount" :currency="$order->currency"
      x-money :amount="$price->current"                (catalogue default)
--}}
@props([
    'amount',
    'currency' => \App\Enums\Currency::default(),
])

@php
    $currency = $currency instanceof \App\Enums\Currency
        ? $currency
        : \App\Enums\Currency::from((string) $currency);
@endphp

<span {{ $attributes }}>{{ $currency->symbol() }}{{ number_format((float) $amount, $currency->minorUnitDigits()) }}</span>
