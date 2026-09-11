<div class="mx-auto flex w-full max-w-md flex-col justify-center px-4 py-16 sm:px-6">

    <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Reset your password</h1>
    <p class="mt-2 text-sm text-ink-500">
        Enter your account email and we'll send you a link to set a new password.
    </p>

    @if ($sent)
        <div role="status"
             class="mt-6 rounded-control border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{-- Deliberately the same message whether or not the email exists —
                 see this component's own docblock. --}}
            If that email has an account, a reset link is on its way. Check your inbox.
        </div>
    @else
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

            <button
                type="submit"
                wire:loading.attr="disabled"
                class="inline-flex w-full items-center justify-center rounded-control bg-ink-900 px-4 py-2.5
                       text-sm font-medium text-white transition-colors duration-200
                       hover:bg-marine-700 disabled:opacity-60
                       focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20"
            >
                <span wire:loading.remove wire:target="submit">Send reset link</span>
                <span wire:loading wire:target="submit">Sending…</span>
            </button>
        </form>
    @endif

    <p class="mt-6 text-sm text-ink-500">
        <a href="/login" wire:navigate
           class="font-medium text-marine-700 underline-offset-4 hover:underline
                  focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20">
            Back to sign in
        </a>
    </p>
</div>
