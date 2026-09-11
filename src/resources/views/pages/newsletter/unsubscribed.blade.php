<x-site.prose-page
    title="{{ $ok ? 'You have been unsubscribed' : 'Link not valid' }}"
    standfirst="{{ $ok
        ? 'This address has been removed from the Amazoff newsletter.'
        : 'That link has already been used or is no longer valid.' }}"
>
    @if ($ok)
        <p>
            You will not receive any more of the newsletter. A confirmation has
            been sent to your address.
        </p>
        <p>
            Changed your mind? Subscribe again from the footer of any page.
        </p>
    @else
        <p>
            If you are still receiving the newsletter, use the unsubscribe link
            in the most recent email, or contact us.
        </p>
    @endif

    <p>
        <a href="{{ route('home') }}">Back to the shop</a>
    </p>
</x-site.prose-page>
