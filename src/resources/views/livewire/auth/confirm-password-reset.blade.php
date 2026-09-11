<div class="mx-auto flex w-full max-w-md flex-col justify-center px-4 py-16 sm:px-6">

    <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Set a new password</h1>

    @if ($invalid)
        <div role="alert"
             class="mt-6 rounded-control border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            This reset link is invalid or has expired.
            <a href="/password/reset" wire:navigate class="font-medium underline underline-offset-4">
                Request a new one
            </a>.
        </div>
    @else
        <p class="mt-2 text-sm text-ink-500">
            Choose a new password for your account.
        </p>

        <form wire:submit="submit" class="mt-8 space-y-5" novalidate>
            <div>
                <label for="email" class="block text-sm font-medium text-ink-800">Email</label>
                <input
                    wire:model="email"
                    id="email" type="email" name="email"
                    autocomplete="username" required autofocus
                    @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                    class="mt-1.5 block w-full rounded-control border border-ink-300 bg-white px-3 py-2.5
                           text-sm text-ink-900 placeholder:text-ink-400
                           focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/20"
                >
                @error('email')
                    <p id="email-error" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-ink-800">New password</label>
                <input wire:model="password" id="password" type="password"
                       autocomplete="new-password" required
                       @error('password') aria-invalid="true" @enderror
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

            <button
                type="submit"
                wire:loading.attr="disabled"
                class="inline-flex w-full items-center justify-center rounded-control bg-ink-900 px-4 py-2.5
                       text-sm font-medium text-white transition-colors duration-200
                       hover:bg-marine-700 disabled:opacity-60
                       focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20"
            >
                <span wire:loading.remove wire:target="submit">Set password</span>
                <span wire:loading wire:target="submit">Saving…</span>
            </button>
        </form>
    @endif
</div>
