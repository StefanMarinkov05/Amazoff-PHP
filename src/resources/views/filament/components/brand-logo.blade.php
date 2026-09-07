{{--
    Admin panel brand mark. Not resources/css/app.css's --color-brand-orange
    custom property — Filament's panel ships its own compiled CSS
    (public/css/filament/...) independent of the storefront's Vite bundle, so
    that token does not exist here. Same sampled value (#FEA001), inlined.
--}}
<span class="fi-logo flex items-center gap-2" style="height: 2rem;">
    <img src="{{ asset('images/logo.png') }}" alt="" class="h-8 w-8 rounded-lg object-cover">
    <span class="text-base font-extrabold italic tracking-tight text-gray-950 dark:text-white">
        Amaz<span class="not-italic" style="color: #FEA001;">off</span>
    </span>
</span>
