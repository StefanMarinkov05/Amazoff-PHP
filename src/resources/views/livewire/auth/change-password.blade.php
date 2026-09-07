<div class="mx-auto flex w-full max-w-md flex-col justify-center px-4 py-16 sm:px-6">

    <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Change password</h1>
    <p class="mt-2 text-sm text-ink-500">
        Signed in as {{ auth()->user()?->email }}.
    </p>

    @if ($saved)
        <div role="status"
             class="mt-6 rounded-card border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            Password updated. Any other devices you were signed in on have been signed out.
        </div>
    @endif

    <form wire:submit="updatePassword" class="mt-8 space-y-5" novalidate>

        <div>
            <label for="current_password" class="block text-sm font-medium text-ink-800">Current password</label>
            <input wire:model="current_password" id="current_password" type="password"
                   autocomplete="current-password" required
                   class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                          text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                          focus:ring-4 focus:ring-marine-600/20">
            @error('current_password')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-ink-800">New password</label>
            <input wire:model="password" id="password" type="password"
                   autocomplete="new-password" required
                   class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                          text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                          focus:ring-4 focus:ring-marine-600/20">
            @error('password')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-ink-800">
                Confirm new password
            </label>
            <input wire:model="password_confirmation" id="password_confirmation" type="password"
                   autocomplete="new-password" required
                   class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                          text-sm text-ink-900 focus:border-marine-600 focus:outline-none
                          focus:ring-4 focus:ring-marine-600/20">
        </div>

        <button type="submit" wire:loading.attr="disabled"
                class="inline-flex w-full items-center justify-center rounded-control bg-ink-900 px-4 py-2.5
                       text-sm font-medium text-white transition-colors duration-200
                       hover:bg-marine-700 disabled:opacity-60
                       focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20">
            <span wire:loading.remove wire:target="updatePassword">Update password</span>
            <span wire:loading wire:target="updatePassword">Updating…</span>
        </button>
    </form>
</div>
