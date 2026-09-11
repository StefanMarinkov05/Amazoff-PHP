<a
    href="/cart"
    @class([
        'group relative inline-flex items-center gap-2 rounded-control px-3 py-2 text-sm font-medium',
        'transition-colors duration-200 hover:bg-ink-100 hover:text-ink-900',
        'focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20',
        'text-ink-900' => $this->count > 0,
        'text-ink-600' => $this->count === 0,
    ])
    aria-label="Basket{{ $this->count > 0 ? ', '.$this->count.' '.Str::plural('item', $this->count) : ', empty' }}"
>
    <span class="relative">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.6" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
        </svg>

        @if ($this->count > 0)
            {{-- Keyed by the label so Livewire replaces the node when the
                 number changes, which is what re-triggers the entrance
                 animation — a morphed-in-place span would sit there silently. --}}
            <span
                wire:key="badge-{{ $label }}"
                aria-hidden="true"
                class="animate-badge-pop absolute -right-2 -top-1.5 grid h-[1.15rem] min-w-[1.15rem]
                       place-items-center rounded-full bg-red-600 px-1 text-[0.62rem] font-bold
                       leading-none text-white shadow-sm shadow-red-600/40 ring-2 ring-ink-50"
            >{{ $label }}</span>
        @endif
    </span>

    <span class="hidden sm:inline">Cart</span>
</a>
