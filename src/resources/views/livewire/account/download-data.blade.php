<div class="mx-auto flex w-full max-w-md flex-col justify-center px-4 py-16 sm:px-6">

    <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Your data</h1>
    <p class="mt-2 text-sm text-ink-500">
        Signed in as {{ auth()->user()?->email }}.
    </p>

    <p class="mt-6 text-sm text-ink-600">
        Download everything Amazoff holds about your account — your profile,
        addresses, orders, reviews, wishlist, newsletter status and any
        messages you have sent us — as a JSON file. Anonymised past orders
        are included and marked as such; the shop is required to keep them as
        invoices.
    </p>

    <form wire:submit="download" class="mt-8">
        <button type="submit" wire:loading.attr="disabled"
                class="inline-flex w-full items-center justify-center rounded-control bg-ink-900 px-4 py-2.5
                       text-sm font-medium text-white transition-colors duration-200
                       hover:bg-marine-700 disabled:opacity-60
                       focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20">
            <span wire:loading.remove wire:target="download">Download my data (JSON)</span>
            <span wire:loading wire:target="download">Preparing…</span>
        </button>
        @error('download')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    </form>

    <p class="mt-6 text-sm text-ink-500">
        Want to delete it instead?
        <a href="{{ route('account.delete') }}" wire:navigate
           class="font-medium text-red-700 underline-offset-4 hover:underline">
            Delete my account
        </a>
    </p>
</div>
