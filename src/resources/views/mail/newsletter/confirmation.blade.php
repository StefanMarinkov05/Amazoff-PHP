<x-mail::message>
# Confirm your subscription

Someone — hopefully you — asked to receive the Amazoff newsletter at this
address. Click below to confirm. We will not send anything until you do.

<x-mail::button :url="$confirmUrl">
Confirm subscription
</x-mail::button>

If you did not ask for this, ignore this email — or
[unsubscribe this address]({{ $unsubscribeUrl }}) so it is not asked again.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
