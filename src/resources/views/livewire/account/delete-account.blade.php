<div class="mx-auto flex w-full max-w-md flex-col justify-center px-4 py-16 sm:px-6">

    <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Delete your account</h1>
    <p class="mt-2 text-sm text-ink-500">
        Signed in as {{ auth()->user()?->email }}.
    </p>

    <div class="mt-6 rounded-card border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        <p class="font-medium">This cannot be undone.</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            <li>Your account, saved addresses, cart and wishlist are permanently deleted.</li>
            <li>Any reviews you left stay on the site, shown as “Anonymous”.</li>
            <li>Your past orders are kept as anonymised invoices — the shop is
                required by law to retain them — with your name, email, phone
                and delivery address removed.</li>
            <li>You will not be able to sign in or see your order history afterwards.</li>
        </ul>
    </div>

    <form wire:submit="deleteAccount" class="mt-8 space-y-5" novalidate>

        <div>
            <label for="current_password" class="block text-sm font-medium text-ink-800">
                Confirm your password
            </label>
            <input wire:model="current_password" id="current_password" type="password"
                   autocomplete="current-password" required
                   class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                          text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                          focus:ring-4 focus:ring-marine-600/20">
            @error('current_password')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="confirmation" class="block text-sm font-medium text-ink-800">
                Type <span class="font-mono font-semibold">DELETE</span> to confirm
            </label>
            <input wire:model="confirmation" id="confirmation" type="text"
                   autocomplete="off" required
                   class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                          text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                          focus:ring-4 focus:ring-marine-600/20">
            @error('confirmation')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <button type="submit" wire:loading.attr="disabled"
                class="inline-flex w-full items-center justify-center rounded-control bg-red-700 px-4 py-2.5
                       text-sm font-medium text-white transition-colors duration-200
                       hover:bg-red-800 disabled:opacity-60
                       focus:outline-none focus-visible:ring-4 focus-visible:ring-red-600/20">
            <span wire:loading.remove wire:target="deleteAccount">Delete my account permanently</span>
            <span wire:loading wire:target="deleteAccount">Deleting…</span>
        </button>
    </form>

    <p class="mt-6 text-sm text-ink-500">
        Changed your mind?
        <a href="{{ route('account.orders') }}" wire:navigate
           class="font-medium text-marine-700 underline-offset-4 hover:underline">
            Back to your account
        </a>
    </p>
</div>
