<div class="bg-ink-50">

    {{-- Compact masthead. A shop's job is to show stock, not a poster —
         eMAG/Ardes give the hero maybe 100px and get to the grid. --}}
    <div class="border-b border-ink-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <nav aria-label="Breadcrumb" class="text-xs text-ink-400">
                <ol class="flex items-center gap-1.5">
                    <li><a href="/" class="hover:text-ink-700">Home</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="font-medium text-ink-700">Catalogue</li>
                </ol>
            </nav>

            <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Catalogue</h1>
                <p class="text-sm text-ink-500">
                    <span class="font-semibold text-ink-900">{{ $products->total() }}</span>
                    {{ Str::plural('product', $products->total()) }}
                </p>
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="lg:grid lg:grid-cols-[15rem_1fr] lg:gap-10">

            {{-- ── Filters ─────────────────────────────────────────────── --}}
            <form
                x-data="{ open: false }"
                class="mb-6 lg:mb-0"
                aria-label="Filter products"
                wire:submit.prevent
            >
                {{-- Mobile disclosure. Baymard: most mobile filtering is poor
                     largely because filters are hidden behind nothing at all. --}}
                <button
                    type="button"
                    x-on:click="open = ! open"
                    :aria-expanded="open ? 'true' : 'false'"
                    class="flex w-full items-center justify-between rounded-control border border-ink-200
                           bg-white px-4 py-2.5 text-sm font-medium lg:hidden
                           focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20"
                >
                    <span>Filters @if (count($this->activeFilters)) <span class="text-marine-700">({{ count($this->activeFilters) }})</span> @endif</span>
                    <svg class="h-4 w-4 transition-transform duration-200" :class="open && 'rotate-180'"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>

                <div
                    x-show="open || window.innerWidth >= 1024"
                    x-cloak
                    class="mt-3 space-y-6 lg:mt-0 lg:!block lg:sticky lg:top-20"
                >
                    <div>
                        <label for="search" class="block text-[0.7rem] font-semibold uppercase tracking-wider text-ink-500">
                            Search
                        </label>
                        <div class="relative mt-2">
                            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-300"
                                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" d="m21 21-5.2-5.2m2.2-5.3a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z" />
                            </svg>
                            <input
                                id="search"
                                type="search"
                                wire:model.live.debounce.300ms="search"
                                placeholder="Search products"
                                class="w-full rounded-control border border-ink-200 bg-white py-2 pl-9 pr-3 text-sm
                                       transition-colors duration-200 placeholder:text-ink-300 hover:border-ink-300
                                       focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/10"
                            >
                        </div>
                    </div>

                    {{-- Toggles first: the two decisions most shoppers make. --}}
                    <fieldset>
                        <legend class="text-[0.7rem] font-semibold uppercase tracking-wider text-ink-500">
                            Availability
                        </legend>
                        <div class="mt-2.5 space-y-2">
                            <label class="flex cursor-pointer items-center gap-2.5 text-sm text-ink-700">
                                <input type="checkbox" wire:model.live="inStockOnly"
                                       class="h-4 w-4 rounded border-ink-300 text-marine-600
                                              focus:ring-4 focus:ring-marine-600/20">
                                In stock only
                            </label>
                            <label class="flex cursor-pointer items-center gap-2.5 text-sm text-ink-700">
                                <input type="checkbox" wire:model.live="onSaleOnly"
                                       class="h-4 w-4 rounded border-ink-300 text-marine-600
                                              focus:ring-4 focus:ring-marine-600/20">
                                On sale
                            </label>
                        </div>
                    </fieldset>

                    {{-- Counts update with the other filters, so no option here
                         can promise results it cannot deliver. --}}
                    <div>
                        <label for="category" class="block text-[0.7rem] font-semibold uppercase tracking-wider text-ink-500">
                            Category
                        </label>
                        <select id="category" wire:model.live="categoryId"
                                class="mt-2 w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-sm
                                       transition-colors duration-200 hover:border-ink-300
                                       focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/10">
                            <option value="">All categories</option>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}">
                                    {{ $category->name }} ({{ $category->products_count }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="brand" class="block text-[0.7rem] font-semibold uppercase tracking-wider text-ink-500">
                            Brand
                        </label>
                        <select id="brand" wire:model.live="brandId"
                                class="mt-2 w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-sm
                                       transition-colors duration-200 hover:border-ink-300
                                       focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/10">
                            <option value="">All brands</option>
                            @foreach ($this->brands as $brand)
                                <option value="{{ $brand->id }}">
                                    {{ $brand->name }} ({{ $brand->products_count }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </form>

            <main>
                {{-- Active filters as dismissible chips. --}}
                @if (count($this->activeFilters))
                    <div class="mb-4 flex flex-wrap items-center gap-2">
                        @foreach ($this->activeFilters as $chip)
                            <button
                                type="button"
                                wire:key="chip-{{ $chip['key'] }}"
                                wire:click="clearFilter('{{ $chip['key'] }}')"
                                class="group inline-flex items-center gap-1.5 rounded-full border border-marine-600/25
                                       bg-marine-50 py-1 pl-3 pr-2 text-xs font-medium text-marine-900
                                       transition-colors duration-200 hover:border-marine-600/50
                                       focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20"
                            >
                                {{ $chip['label'] }}
                                <span aria-hidden="true"
                                      class="grid h-4 w-4 place-items-center rounded-full bg-marine-600/10
                                             transition-colors duration-200 group-hover:bg-marine-600 group-hover:text-white">×</span>
                                <span class="sr-only">Remove filter</span>
                            </button>
                        @endforeach

                        <button type="button" wire:click="clearFilters"
                                class="ml-1 text-xs font-medium text-ink-500 underline-offset-4 hover:text-ink-900 hover:underline">
                            Clear all
                        </button>
                    </div>
                @endif

                <div class="mb-5 flex flex-wrap items-center gap-2 border-b border-ink-200 pb-4">
                    <span class="mr-1 text-[0.7rem] font-semibold uppercase tracking-wider text-ink-400">Sort</span>

                    @foreach (\App\Livewire\Catalogue\ProductList::SORTS as $column => $label)
                        @php($active = $sortBy === $column)
                        <button type="button" wire:click="setSortOrder('{{ $column }}')"
                                @if ($active) aria-current="true" @endif
                                class="inline-flex items-center gap-1 rounded-control px-3 py-1.5 text-sm font-medium
                                       transition-all duration-200 focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20
                                       {{ $active ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">
                            {{ $label }}
                            @if ($active)
                                <span aria-hidden="true"
                                      class="text-[0.6rem] transition-transform duration-300 {{ $sortDir === 'asc' ? 'rotate-180' : '' }}">▾</span>
                                <span class="sr-only">{{ $sortDir === 'asc' ? 'ascending' : 'descending' }}</span>
                            @endif
                        </button>
                    @endforeach

                    <span wire:loading class="ml-auto inline-flex items-center gap-2 text-sm text-ink-400">
                        <span aria-hidden="true" class="h-1.5 w-1.5 animate-pulse rounded-full bg-marine-500"></span>
                        Updating
                    </span>
                </div>

                {{-- ── Grid ────────────────────────────────────────────── --}}
                <div wire:loading.class="opacity-40" class="transition-opacity duration-200">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-8 sm:gap-x-6 lg:grid-cols-3 xl:grid-cols-4">
                        @forelse ($products as $index => $product)
                            @php($image = $product->productImages->firstWhere('is_main', true) ?? $product->productImages->first())
                            @php($onSale = $this->discountIsActive($product))
                            @php($percent = $this->discountPercent($product))
                            @php($stock = $this->availableStock($product))
                            @php($rating = $product->rating_avg ? round((float) $product->rating_avg, 1) : null)

                            <article
                                wire:key="product-{{ $product->id }}"
                                style="animation-delay: {{ min($index * 40, 320) }}ms"
                                class="group animate-card-in flex flex-col overflow-hidden rounded-card border
                                       border-ink-200 bg-white transition-all duration-300
                                       hover:-translate-y-0.5 hover:border-marine-600/40 hover:shadow-lg hover:shadow-ink-900/5"
                            >
                                <a href="/products/{{ $product->slug }}" class="relative block aspect-square overflow-hidden bg-ink-50">
                                    @if ($image)
                                        <img src="{{ Storage::url($image->path) }}"
                                             alt="{{ $image->alt_text ?? $product->name }}" loading="lazy"
                                             class="h-full w-full object-cover transition-transform duration-500
                                                    ease-[cubic-bezier(0.25,1,0.5,1)] group-hover:scale-[1.06]">
                                    @else
                                        <div class="flex h-full flex-col items-center justify-center gap-1.5 text-ink-300">
                                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                 stroke-width="1.25" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                      d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M18 9h.008v.008H18V9Zm.75 12H5.25A2.25 2.25 0 0 1 3 18.75V5.25A2.25 2.25 0 0 1 5.25 3h13.5A2.25 2.25 0 0 1 21 5.25v13.5A2.25 2.25 0 0 1 18.75 21Z" />
                                            </svg>
                                            <span class="text-[0.6rem] uppercase tracking-[0.15em]">No image</span>
                                        </div>
                                    @endif

                                    {{-- Badges: saving first, then scarcity. Both are facts
                                         from the data, never decoration. --}}
                                    <div class="absolute left-2.5 top-2.5 flex flex-col items-start gap-1.5">
                                        @if ($percent > 0)
                                            <span class="rounded bg-marine-600 px-1.5 py-0.5 text-[0.65rem] font-bold text-white">
                                                −{{ $percent }}%
                                            </span>
                                        @endif

                                        @if ($stock === 0)
                                            <span class="rounded bg-ink-900/80 px-1.5 py-0.5 text-[0.65rem] font-semibold text-white backdrop-blur-sm">
                                                Out of stock
                                            </span>
                                        @elseif ($stock <= 3)
                                            <span class="rounded bg-amber-500 px-1.5 py-0.5 text-[0.65rem] font-semibold text-ink-950">
                                                Only {{ $stock }} left
                                            </span>
                                        @endif
                                    </div>
                                </a>

                                <div class="flex flex-1 flex-col p-3.5">
                                    @if ($product->brand)
                                        <p class="text-[0.65rem] font-medium uppercase tracking-[0.1em] text-ink-400">
                                            {{ $product->brand->name }}
                                        </p>
                                    @endif

                                    <h2 class="mt-1 text-sm font-medium leading-snug">
                                        <a href="/products/{{ $product->slug }}"
                                           class="line-clamp-2 transition-colors duration-200 hover:text-marine-700
                                                  focus:outline-none focus-visible:underline">
                                            {{ $product->name }}
                                        </a>
                                    </h2>

                                    {{-- Rating, only when there is one. An empty
                                         five-star row reads as zero stars. --}}
                                    @if ($rating)
                                        <div class="mt-1.5 flex items-center gap-1.5">
                                            <div class="flex" aria-hidden="true">
                                                @for ($i = 1; $i <= 5; $i++)
                                                    <svg class="h-3.5 w-3.5 {{ $i <= round($rating) ? 'text-amber-400' : 'text-ink-200' }}"
                                                         viewBox="0 0 20 20" fill="currentColor">
                                                        <path d="M10 15.27 16.18 19l-1.64-7.03L20 7.24l-7.19-.61L10 0 7.19 6.63 0 7.24l5.46 4.73L3.82 19z"/>
                                                    </svg>
                                                @endfor
                                            </div>
                                            <span class="text-xs text-ink-400">
                                                {{ number_format($rating, 1) }}
                                                <span class="sr-only">out of 5 from {{ $product->rating_count }} reviews</span>
                                                <span aria-hidden="true">({{ $product->rating_count }})</span>
                                            </span>
                                        </div>
                                    @endif

                                    <div class="mt-auto pt-3">
                                        <div class="flex items-baseline gap-1.5">
                                            @if ($onSale)
                                                <span class="text-base font-bold tabular-nums text-marine-700">
                                                    €{{ number_format((float) $product->discount_price, 2) }}
                                                </span>
                                                <span class="text-xs tabular-nums text-ink-400 line-through">
                                                    €{{ number_format((float) $product->regular_price, 2) }}
                                                </span>
                                            @else
                                                <span class="text-base font-bold tabular-nums">
                                                    €{{ number_format((float) $product->regular_price, 2) }}
                                                </span>
                                            @endif
                                        </div>

                                        <p class="mt-0.5 text-[0.65rem] text-ink-400">VAT included</p>

                                        {{-- Reveals on hover at desktop width; always
                                             visible on touch, where hover does not exist. --}}
                                        <a href="/products/{{ $product->slug }}"
                                           class="mt-3 flex w-full items-center justify-center gap-1.5 rounded-control
                                                  bg-ink-900 px-3 py-2 text-xs font-semibold text-white
                                                  transition-all duration-200 hover:bg-marine-700
                                                  focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/25
                                                  lg:opacity-0 lg:group-hover:opacity-100 lg:group-focus-within:opacity-100">
                                            View product
                                        </a>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div class="col-span-full rounded-card border border-dashed border-ink-300 bg-white/60 px-6 py-20 text-center">
                                <div class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-ink-100">
                                    <svg class="h-6 w-6 text-ink-400" fill="none" viewBox="0 0 24 24"
                                         stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                    </svg>
                                </div>
                                <p class="mt-4 text-lg font-medium">Nothing matches those filters</p>
                                <p class="mt-1 text-ink-500">Try a broader search, or remove a filter.</p>
                                <button type="button" wire:click="clearFilters"
                                        class="mt-6 rounded-control bg-ink-900 px-4 py-2 text-sm font-medium text-white
                                               transition-colors duration-200 hover:bg-marine-700
                                               focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/25">
                                    Clear all filters
                                </button>
                            </div>
                        @endforelse
                    </div>
                </div>

                @if ($products->hasPages())
                    <nav class="mt-10 border-t border-ink-200 pt-6" aria-label="Pagination">
                        {{ $products->onEachSide(1)->links() }}
                    </nav>
                @endif
            </main>
        </div>
    </div>
</div>
