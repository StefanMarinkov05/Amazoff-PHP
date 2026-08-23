<div class="min-h-screen bg-ink-50 text-ink-900 antialiased">

    {{-- Full-bleed masthead. Deep ink, so the white content below reads as
         "the shop floor" and this reads as the sign above the door. --}}
    <header class="relative overflow-hidden bg-ink-950">
        {{-- One soft marine wash, same hue family as the accent. Not a
             purple-to-blue hero gradient. --}}
        <div
            aria-hidden="true"
            class="pointer-events-none absolute -right-32 -top-40 h-[28rem] w-[28rem] rounded-full
                   bg-marine-600/20 blur-3xl"
        ></div>

        <div class="relative mx-auto max-w-7xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
            <p class="text-xs font-semibold uppercase tracking-[0.25em] text-marine-300">
                Catalogue
            </p>

            {{-- The one confident element. Tight leading at display size. --}}
            <h1 class="mt-3 max-w-3xl text-4xl font-semibold leading-[1.05] tracking-tight text-white sm:text-6xl">
                Everything we stock,
                <span class="text-marine-300">in one place.</span>
            </h1>

            <p class="mt-5 max-w-prose text-base leading-relaxed text-ink-300">
                {{ $products->total() }} {{ Str::plural('product', $products->total()) }} available right now.
            </p>
        </div>
    </header>

    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="lg:grid lg:grid-cols-[15rem_1fr] lg:gap-12">

            {{-- Filters --}}
            <form class="mb-10 lg:mb-0" aria-label="Filter products" wire:submit.prevent>
                <div class="space-y-7 lg:sticky lg:top-8">

                    <div>
                        <label for="search" class="block text-xs font-semibold uppercase tracking-wider text-ink-500">
                            Search
                        </label>
                        <input
                            id="search"
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Product name…"
                            class="mt-2 w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-sm
                                   transition-colors duration-200 placeholder:text-ink-300
                                   hover:border-ink-300
                                   focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/10"
                        >
                    </div>

                    <div>
                        <label for="category" class="block text-xs font-semibold uppercase tracking-wider text-ink-500">
                            Category
                        </label>
                        <select
                            id="category"
                            wire:model.live="categoryId"
                            class="mt-2 w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-sm
                                   transition-colors duration-200 hover:border-ink-300
                                   focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/10"
                        >
                            <option value="">All categories</option>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="brand" class="block text-xs font-semibold uppercase tracking-wider text-ink-500">
                            Brand
                        </label>
                        <select
                            id="brand"
                            wire:model.live="brandId"
                            class="mt-2 w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-sm
                                   transition-colors duration-200 hover:border-ink-300
                                   focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/10"
                        >
                            <option value="">All brands</option>
                            @foreach ($this->brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if ($this->hasFilters)
                        <button
                            type="button"
                            wire:click="clearFilters"
                            class="group inline-flex items-center gap-1.5 text-sm font-medium text-marine-700
                                   transition-colors duration-200 hover:text-marine-900
                                   focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20"
                        >
                            <span aria-hidden="true"
                                  class="transition-transform duration-200 group-hover:-translate-x-0.5">←</span>
                            Clear filters
                        </button>
                    @endif
                </div>
            </form>

            <main>
                {{-- Sort --}}
                <div class="mb-8 flex flex-wrap items-center gap-2 border-b border-ink-200 pb-5">
                    <span class="mr-1 text-xs font-semibold uppercase tracking-wider text-ink-400">Sort</span>

                    @foreach (\App\Livewire\Catalogue\ProductList::SORTS as $column => $label)
                        @php($active = $sortBy === $column)
                        <button
                            type="button"
                            wire:click="setSortOrder('{{ $column }}')"
                            @if ($active) aria-current="true" @endif
                            class="inline-flex items-center gap-1 rounded-control px-3 py-1.5 text-sm font-medium
                                   transition-all duration-200
                                   focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20
                                   {{ $active
                                        ? 'bg-ink-900 text-white shadow-sm'
                                        : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}"
                        >
                            {{ $label }}
                            @if ($active)
                                {{-- Rotates rather than swapping glyphs: the arrow
                                     is the same object changing direction. --}}
                                <span
                                    aria-hidden="true"
                                    class="text-xs transition-transform duration-300 {{ $sortDir === 'asc' ? 'rotate-180' : '' }}"
                                >▾</span>
                                <span class="sr-only">{{ $sortDir === 'asc' ? 'ascending' : 'descending' }}</span>
                            @endif
                        </button>
                    @endforeach

                    <span
                        wire:loading
                        class="ml-auto inline-flex items-center gap-2 text-sm text-ink-400"
                    >
                        <span aria-hidden="true"
                              class="h-1.5 w-1.5 animate-pulse rounded-full bg-marine-500"></span>
                        Updating
                    </span>
                </div>

                <div wire:loading.class="opacity-40" class="transition-opacity duration-200">
                    <div class="grid grid-cols-1 gap-x-6 gap-y-10 sm:grid-cols-2 xl:grid-cols-3">
                        @forelse ($products as $index => $product)
                            @php($image = $product->productImages->firstWhere('is_main', true) ?? $product->productImages->first())
                            @php($onSale = $this->discountIsActive($product))

                            {{-- wire:key is required: without it Livewire reuses DOM
                                 nodes across filter changes and cards go stale. --}}
                            <article
                                wire:key="product-{{ $product->id }}"
                                style="animation-delay: {{ min($index * 45, 360) }}ms"
                                class="group animate-card-in flex flex-col"
                            >
                                <div class="relative aspect-[4/3] overflow-hidden rounded-card bg-ink-100
                                            ring-1 ring-ink-200/70 transition-all duration-300
                                            group-hover:ring-marine-600/40 group-hover:ring-2">
                                    @if ($image)
                                        <img
                                            src="{{ Storage::url($image->path) }}"
                                            alt="{{ $image->alt_text ?? $product->name }}"
                                            loading="lazy"
                                            class="h-full w-full object-cover transition-transform duration-500
                                                   ease-[cubic-bezier(0.25,1,0.5,1)] group-hover:scale-105"
                                        >
                                    @else
                                        {{-- A designed placeholder, not a broken image. --}}
                                        <div class="flex h-full flex-col items-center justify-center gap-2 text-ink-300">
                                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24"
                                                 stroke="currentColor" stroke-width="1.25" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                      d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M18 9h.008v.008H18V9Zm.75 12H5.25A2.25 2.25 0 0 1 3 18.75V5.25A2.25 2.25 0 0 1 5.25 3h13.5A2.25 2.25 0 0 1 21 5.25v13.5A2.25 2.25 0 0 1 18.75 21Z" />
                                            </svg>
                                            <span class="text-[0.65rem] uppercase tracking-[0.15em]">No image</span>
                                        </div>
                                    @endif

                                    @if ($onSale)
                                        <span class="absolute left-3 top-3 rounded bg-marine-600 px-2 py-1
                                                     text-[0.65rem] font-semibold uppercase tracking-wider text-white">
                                            Sale
                                        </span>
                                    @endif
                                </div>

                                <div class="flex flex-1 flex-col pt-4">
                                    @if ($product->brand)
                                        <p class="text-[0.7rem] font-medium uppercase tracking-[0.12em] text-ink-400">
                                            {{ $product->brand->name }}
                                        </p>
                                    @endif

                                    <h2 class="mt-1.5 text-base font-medium leading-snug text-ink-900">
                                        {{-- Underline grows from the left on hover. The one
                                             signature interaction; everything else is subtle. --}}
                                        <span class="bg-gradient-to-r from-marine-600 to-marine-600 bg-[length:0%_1px]
                                                     bg-left-bottom bg-no-repeat transition-[background-size] duration-300
                                                     group-hover:bg-[length:100%_1px]">
                                            {{ $product->name }}
                                        </span>
                                    </h2>

                                    <div class="mt-auto flex items-baseline gap-2 pt-4">
                                        @if ($onSale)
                                            <span class="text-lg font-semibold tabular-nums text-marine-700">
                                                €{{ number_format((float) $product->discount_price, 2) }}
                                            </span>
                                            <span class="text-sm tabular-nums text-ink-400 line-through">
                                                €{{ number_format((float) $product->regular_price, 2) }}
                                            </span>
                                        @else
                                            <span class="text-lg font-semibold tabular-nums text-ink-900">
                                                €{{ number_format((float) $product->regular_price, 2) }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </article>
                        @empty
                            {{-- The empty state gets the same care as the grid. --}}
                            <div class="col-span-full rounded-card border border-dashed border-ink-300
                                        bg-white/60 px-6 py-20 text-center">
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-ink-100">
                                    <svg class="h-6 w-6 text-ink-400" fill="none" viewBox="0 0 24 24"
                                         stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                    </svg>
                                </div>
                                <p class="mt-4 text-lg font-medium text-ink-900">Nothing matches those filters</p>
                                <p class="mt-1 text-ink-500">Try a broader search, or a different category.</p>

                                @if ($this->hasFilters)
                                    <button
                                        type="button"
                                        wire:click="clearFilters"
                                        class="mt-6 rounded-control bg-ink-900 px-4 py-2 text-sm font-medium text-white
                                               transition-all duration-200 hover:bg-marine-700
                                               focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/25"
                                    >
                                        Clear all filters
                                    </button>
                                @endif
                            </div>
                        @endforelse
                    </div>
                </div>

                @if ($products->hasPages())
                    <nav class="mt-12 border-t border-ink-200 pt-6" aria-label="Pagination">
                        {{ $products->onEachSide(1)->links() }}
                    </nav>
                @endif
            </main>
        </div>
    </div>
</div>
