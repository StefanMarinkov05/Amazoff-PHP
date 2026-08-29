<div class="bg-ink-50">
    <div class="mx-auto max-w-5xl px-4 py-14 sm:px-6 lg:py-20">

        <nav aria-label="Breadcrumb" class="mb-8 text-xs text-ink-400">
            <ol class="flex items-center gap-1.5">
                <li><a href="/" class="transition-colors hover:text-marine-700">Home</a></li>
                <li aria-hidden="true" class="text-ink-300">/</li>
                <li class="font-medium text-ink-600" aria-current="page">Contact</li>
            </ol>
        </nav>

        <div class="grid gap-12 md:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] md:gap-16">

            {{-- ── Side ──────────────────────────────────────────────── --}}
            <div>
                <h1 class="text-3xl font-semibold tracking-tight text-ink-900 sm:text-4xl">
                    Talk to us
                </h1>
                <div aria-hidden="true" class="mt-4 h-1 w-12 rounded-full bg-marine-600"></div>

                <p class="mt-6 text-[0.95rem] leading-relaxed text-ink-600">
                    Questions about an order, a product, or a return. A person reads
                    every message and replies within one working day.
                </p>

                <dl class="mt-10 space-y-6">
                    @foreach ([
                        ['M2.25 6.75A2.25 2.25 0 0 1 4.5 4.5h15a2.25 2.25 0 0 1 2.25 2.25v10.5A2.25 2.25 0 0 1 19.5 19.5h-15a2.25 2.25 0 0 1-2.25-2.25V6.75Z M2.7 6.4l9.3 6.6 9.3-6.6', 'Email', 'hello@online-shop.test'],
                        ['M12 6v6l4 2 M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z', 'Hours', 'Monday to Friday, 9:00 – 18:00 EET'],
                        ['M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z', 'Returns', 'Sofia, Bulgaria — address supplied with your return code'],
                    ] as $index => [$path, $term, $detail])
                        <div class="animate-card-in flex gap-4" style="animation-delay: {{ $index * 80 }}ms">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-control
                                         bg-marine-600/10 text-marine-700">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                     stroke-width="1.7" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}" />
                                </svg>
                            </span>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-[0.12em] text-ink-400">{{ $term }}</dt>
                                <dd class="mt-1 text-sm text-ink-800">{{ $detail }}</dd>
                            </div>
                        </div>
                    @endforeach
                </dl>
            </div>

            {{-- ── Form ──────────────────────────────────────────────── --}}
            <div class="rounded-card border border-ink-200 bg-white p-6 shadow-sm sm:p-8">
                @if ($sent)
                    <div class="animate-card-in py-8 text-center">
                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-emerald-50 text-emerald-600">
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                 stroke-width="2.2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                        </span>

                        <h2 class="mt-5 text-lg font-semibold text-ink-900">Message sent</h2>
                        <p class="mx-auto mt-2 max-w-xs text-sm leading-relaxed text-ink-600">
                            Thanks — we have it. Expect a reply within one working day.
                        </p>

                        <button
                            type="button"
                            wire:click="$set('sent', false)"
                            class="mt-6 text-sm font-medium text-marine-700 underline-offset-4
                                   transition-colors hover:text-marine-600 hover:underline
                                   focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/25"
                        >
                            Send another
                        </button>
                    </div>
                @else
                    <form wire:submit="submit" class="space-y-5" novalidate>
                        {{-- Honeypot. Hidden from sight and from screen readers, but
                             present in the DOM for anything filling fields blindly. --}}
                        <div class="hidden" aria-hidden="true">
                            <label for="website">Website</label>
                            <input id="website" type="text" tabindex="-1" autocomplete="off" wire:model="website">
                        </div>

                        <div>
                            <label for="name" class="block text-xs font-medium uppercase tracking-[0.1em] text-ink-500">
                                Name
                            </label>
                            <input
                                id="name"
                                type="text"
                                wire:model.blur="name"
                                maxlength="50"
                                @error('name') aria-invalid="true" @enderror
                                class="mt-2 block w-full rounded-control border bg-white px-3.5 py-2.5 text-sm
                                       text-ink-900 transition-all duration-200 placeholder:text-ink-300
                                       focus:outline-none focus:ring-4
                                       @error('name') border-red-400 focus:border-red-500 focus:ring-red-500/20
                                       @else border-ink-200 focus:border-marine-600 focus:ring-marine-600/20 @enderror"
                                placeholder="Your name"
                            >
                            @error('name')
                                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="email" class="block text-xs font-medium uppercase tracking-[0.1em] text-ink-500">
                                Email
                            </label>
                            <input
                                id="email"
                                type="email"
                                wire:model.blur="email"
                                maxlength="100"
                                @error('email') aria-invalid="true" @enderror
                                class="mt-2 block w-full rounded-control border bg-white px-3.5 py-2.5 text-sm
                                       text-ink-900 transition-all duration-200 placeholder:text-ink-300
                                       focus:outline-none focus:ring-4
                                       @error('email') border-red-400 focus:border-red-500 focus:ring-red-500/20
                                       @else border-ink-200 focus:border-marine-600 focus:ring-marine-600/20 @enderror"
                                placeholder="you@example.com"
                            >
                            @error('email')
                                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="subject" class="block text-xs font-medium uppercase tracking-[0.1em] text-ink-500">
                                Subject <span class="normal-case tracking-normal text-ink-300">— optional</span>
                            </label>
                            <input
                                id="subject"
                                type="text"
                                wire:model.blur="subject"
                                maxlength="100"
                                class="mt-2 block w-full rounded-control border bg-white px-3.5 py-2.5 text-sm
                                       text-ink-900 transition-all duration-200 placeholder:text-ink-300
                                       focus:outline-none focus:ring-4
                                       @error('subject') border-red-400 focus:border-red-500 focus:ring-red-500/20
                                       @else border-ink-200 focus:border-marine-600 focus:ring-marine-600/20 @enderror"
                                placeholder="Order #1234, a product question…"
                            >
                            @error('subject')
                                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <div class="flex items-baseline justify-between">
                                <label for="message" class="block text-xs font-medium uppercase tracking-[0.1em] text-ink-500">
                                    Message
                                </label>
                                <span class="text-xs tabular-nums text-ink-300">
                                    {{ mb_strlen($message ?? '') }}/2000
                                </span>
                            </div>
                            <textarea
                                id="message"
                                rows="6"
                                wire:model.blur="message"
                                maxlength="2000"
                                @error('message') aria-invalid="true" @enderror
                                class="mt-2 block w-full resize-y rounded-control border bg-white px-3.5 py-2.5
                                       text-sm leading-relaxed text-ink-900 transition-all duration-200
                                       placeholder:text-ink-300 focus:outline-none focus:ring-4
                                       @error('message') border-red-400 focus:border-red-500 focus:ring-red-500/20
                                       @else border-ink-200 focus:border-marine-600 focus:ring-marine-600/20 @enderror"
                                placeholder="How can we help?"
                            ></textarea>
                            @error('message')
                                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="submit"
                            class="group flex w-full items-center justify-center gap-2 rounded-control
                                   bg-ink-900 px-5 py-3 text-sm font-semibold text-white
                                   transition-all duration-200 hover:-translate-y-0.5 hover:bg-marine-700
                                   hover:shadow-lg hover:shadow-marine-700/25
                                   focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/25
                                   disabled:cursor-not-allowed disabled:translate-y-0 disabled:bg-ink-300"
                        >
                            <svg wire:loading wire:target="submit"
                                 class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor"
                                      d="M4 12a8 8 0 0 1 8-8V0C5.4 0 0 5.4 0 12h4z"/>
                            </svg>

                            <span wire:loading.remove wire:target="submit">Send message</span>
                            <span wire:loading wire:target="submit">Sending…</span>

                            <svg wire:loading.remove wire:target="submit"
                                 class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12l-7.5 7.5M21 12H3" />
                            </svg>
                        </button>

                        <p class="text-center text-xs leading-relaxed text-ink-400">
                            We use your details to answer this message and nothing else.
                        </p>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
