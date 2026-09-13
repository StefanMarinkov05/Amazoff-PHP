@props(['tone' => 'dark'])

{{--
    The "Amazoff" text mark: two-tone split ("Amaz" solid, "off" in
    --color-brand-orange) plus the curled underline from public/images/logo.png.
    header.blade.php is the reference every other rendering copies; this
    component exists so header, footer, and the home hero can't drift.
--}}
<span {{ $attributes->class(['relative text-[1.05rem] font-extrabold italic tracking-tight', 'text-ink-900' => $tone === 'dark', 'text-white' => $tone === 'light']) }}>
    Amaz<span class="text-brand-orange not-italic">off</span>
    <svg
        class="pointer-events-none absolute -bottom-1.5 left-[0.2em] h-2 w-[4.4em]"
        viewBox="0 0 100 18" fill="none" aria-hidden="true"
    >
        <path
            d="M2 4c22 14 68 14 92 4"
            stroke="var(--color-brand-orange)" stroke-width="4" stroke-linecap="round"
        />
        <path
            d="M84 3.5 96 8l-9 8"
            stroke="var(--color-brand-orange)" stroke-width="4"
            stroke-linecap="round" stroke-linejoin="round"
        />
    </svg>
</span>
