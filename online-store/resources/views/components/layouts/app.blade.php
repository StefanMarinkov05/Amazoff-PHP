<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'Online Shop' }}</title>

        {{-- Vite::fonts() is separate from @vite and easy to miss: the plugin
             writes public/fonts-manifest.dev.json but nothing injects it, so
             without this line Instrument Sans never loads and the whole page
             silently falls back to the system UI font. --}}
        {{ Vite::fonts() }}
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex min-h-full flex-col bg-ink-50 font-sans text-ink-900 antialiased">

        {{-- Skip link: the first thing a keyboard user reaches, hidden until focused. --}}
        <a
            href="#main-content"
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50
                   focus:rounded-control focus:bg-ink-900 focus:px-4 focus:py-2 focus:text-sm
                   focus:font-medium focus:text-white"
        >
            Skip to content
        </a>

        <x-site.header />

        <main id="main-content" class="flex-1">
            {{ $slot }}
        </main>

        <x-site.footer />
    </body>
</html>
