<div>
    @if ($submitted)
        <p class="animate-card-in flex items-start gap-2 text-sm text-marine-300">
            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                 stroke-width="2.4" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
            Check your inbox — click the link in the email to confirm.
        </p>
    @else
        <form wire:submit="subscribe" class="flex gap-2" novalidate>
            <label for="newsletter-email" class="sr-only">Email address</label>
            <input
                id="newsletter-email"
                type="email"
                wire:model.blur="email"
                maxlength="100"
                placeholder="you@example.com"
                @error('email') aria-invalid="true" @enderror
                class="min-w-0 flex-1 rounded-control border bg-ink-900 px-3 py-2 text-sm text-white
                       transition-all duration-200 placeholder:text-ink-500
                       focus:outline-none focus:ring-4
                       @error('email') border-red-500/70 focus:ring-red-500/25
                       @else border-ink-700 focus:border-marine-300 focus:ring-marine-300/25 @enderror"
            >

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="subscribe"
                class="shrink-0 rounded-control bg-marine-600 px-4 py-2 text-sm font-semibold text-white
                       transition-all duration-200 hover:bg-marine-500
                       focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-300/40
                       disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="subscribe">Join</span>
                <span wire:loading wire:target="subscribe">…</span>
            </button>
        </form>

        @error('email')
            <p class="mt-2 text-xs text-red-400">{{ $message }}</p>
        @enderror
    @endif
</div>
