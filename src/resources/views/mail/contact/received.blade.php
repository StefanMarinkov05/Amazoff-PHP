<x-mail::message>
# New contact message

Replying to this email goes to the sender.

{{-- Raw on purpose: a code block escapes its own content, and {{ }} would double-escape it. --}}
{!! $fence !!}text
{!! $body !!}
{!! $fence !!}

<x-mail::button :url="$panelUrl">
Open in the admin panel
</x-mail::button>

Mark it handled from that page once it has been dealt with.
</x-mail::message>
