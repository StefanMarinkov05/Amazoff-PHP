<x-site.prose-page
    title="{{ $ok ? 'Subscription confirmed' : 'Link not valid' }}"
    standfirst="{{ $ok
        ? 'You are on the Amazoff newsletter. Expect the odd update, no more than once a month.'
        : 'That confirmation link has already been used or is no longer valid.' }}"
>
    @if ($ok)
        <p>
            You can unsubscribe at any time from the link in every email we send,
            or from the footer of any page.
        </p>
    @else
        <p>
            If you still want to subscribe, enter your address again in the
            footer of any page and we will send a fresh confirmation link.
        </p>
    @endif

    <p>
        <a href="{{ route('home') }}">Back to the shop</a>
    </p>
</x-site.prose-page>
