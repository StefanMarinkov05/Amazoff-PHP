<div class="bg-ink-50">

    {{-- Compact masthead. A shop's job is to show stock, not a poster —
         eMAG/Ardes give the hero maybe 100px and get to the grid. --}}
    <div class="border-b border-ink-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <nav aria-label="Breadcrumb" class="text-xs text-ink-400">
                <ol class="flex items-center gap-1.5">
                    <li><a href="/" class="-my-1 inline-block py-1 hover:text-ink-700">Home</a></li>
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
                         can promise results it cannot deliver.

                         A custom hover/click panel rather than a native
                         <select> — same reasoning as the attribute facets
                         below: a native popup's own rendering is outside
                         this page's control (its width, position, and
                         open/close timing all belong to the browser, not to
                         this CSS), where a plain absolutely-positioned panel
                         behaves exactly like the rest of the page. Single-
                         select: choosing a category (or brand) closes the
                         panel immediately, since there is only ever one
                         active choice, unlike the multi-value attribute
                         facets. --}}
                    <div x-data="{ open: false }" x-on:mouseenter="open = true" x-on:mouseleave="open = false"
                         x-on:keydown.escape.window="open = false" class="relative">
                        <span class="block text-[0.7rem] font-semibold uppercase tracking-wider text-ink-500">
                            Category
                        </span>
                        <button
                            type="button"
                            x-on:focus="open = true"
                            x-on:click="open = ! open"
                            :aria-expanded="open ? 'true' : 'false'"
                            class="mt-2 flex w-full items-center justify-between gap-2 rounded-control border border-ink-200
                                   bg-white px-3 py-2 text-left text-sm transition-colors duration-200 hover:border-ink-300
                                   focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/10"
                        >
                            <span class="truncate">
                                {{ $this->selectedCategory()?->name ?? 'All categories' }}
                            </span>
                            <svg class="h-3.5 w-3.5 shrink-0 text-ink-400 transition-transform duration-200" :class="open && 'rotate-180'"
                                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>

                        <div
                            x-show="open" x-cloak
                            x-on:focusout="if (! $el.contains($event.relatedTarget)) open = false"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                            class="absolute left-0 top-full z-20 w-full pt-1.5"
                        >
                            <div class="max-h-72 overflow-y-auto rounded-card border border-ink-200 bg-white p-1.5 shadow-xl shadow-ink-900/10">
                                {{-- Depth-first order (ResolveCategoryFamily::orderedTreeWithDepth):
                                     parent immediately before its own children, indented by depth. --}}
                                <button
                                    type="button"
                                    x-on:click="open = false"
                                    wire:click="$set('categorySlug', null)"
                                    class="flex w-full items-center justify-between gap-2 rounded-control px-2.5 py-1.5 text-left text-sm
                                           transition-colors duration-150 focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20
                                           {{ $this->categorySlug === null ? 'bg-marine-50 font-medium text-marine-900' : 'text-ink-700 hover:bg-ink-50' }}"
                                >
                                    All categories
                                </button>
                                @foreach ($this->categories as $category)
                                    <button
                                        type="button"
                                        x-on:click="open = false"
                                        wire:click="$set('categorySlug', '{{ $category->slug }}')"
                                        style="padding-left: {{ 0.625 + $category->depth * 0.9 }}rem"
                                        class="flex w-full items-center justify-between gap-2 rounded-control py-1.5 pr-2.5 text-left text-sm
                                               transition-colors duration-150 focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20
                                               {{ $this->categorySlug === $category->slug ? 'bg-marine-50 font-medium text-marine-900' : 'text-ink-700 hover:bg-ink-50' }}"
                                    >
                                        <span class="truncate">{{ $category->name }}</span>
                                        <span class="shrink-0 text-xs text-ink-400">{{ $category->products_count }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div x-data="{ open: false }" x-on:mouseenter="open = true" x-on:mouseleave="open = false"
                         x-on:keydown.escape.window="open = false" class="relative">
                        <span class="block text-[0.7rem] font-semibold uppercase tracking-wider text-ink-500">
                            Brand
                        </span>
                        <button
                            type="button"
                            x-on:focus="open = true"
                            x-on:click="open = ! open"
                            :aria-expanded="open ? 'true' : 'false'"
                            class="mt-2 flex w-full items-center justify-between gap-2 rounded-control border border-ink-200
                                   bg-white px-3 py-2 text-left text-sm transition-colors duration-200 hover:border-ink-300
                                   focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/10"
                        >
                            <span class="truncate">
                                {{ $this->brandId !== null ? $this->brands->firstWhere('id', $this->brandId)?->name : 'All brands' }}
                            </span>
                            <svg class="h-3.5 w-3.5 shrink-0 text-ink-400 transition-transform duration-200" :class="open && 'rotate-180'"
                                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>

                        <div
                            x-show="open" x-cloak
                            x-on:focusout="if (! $el.contains($event.relatedTarget)) open = false"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                            class="absolute left-0 top-full z-20 w-full pt-1.5"
                        >
                            <div class="max-h-72 overflow-y-auto rounded-card border border-ink-200 bg-white p-1.5 shadow-xl shadow-ink-900/10">
                                <button
                                    type="button"
                                    x-on:click="open = false"
                                    wire:click="$set('brandId', null)"
                                    class="flex w-full items-center justify-between gap-2 rounded-control px-2.5 py-1.5 text-left text-sm
                                           transition-colors duration-150 focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20
                                           {{ $this->brandId === null ? 'bg-marine-50 font-medium text-marine-900' : 'text-ink-700 hover:bg-ink-50' }}"
                                >
                                    All brands
                                </button>
                                @foreach ($this->brands as $brand)
                                    <button
                                        type="button"
                                        x-on:click="open = false"
                                        wire:click="$set('brandId', {{ $brand->id }})"
                                        class="flex w-full items-center justify-between gap-2 rounded-control px-2.5 py-1.5 text-left text-sm
                                               transition-colors duration-150 focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20
                                               {{ $this->brandId === $brand->id ? 'bg-marine-50 font-medium text-marine-900' : 'text-ink-700 hover:bg-ink-50' }}"
                                    >
                                        <span class="truncate">{{ $brand->name }}</span>
                                        <span class="shrink-0 text-xs text-ink-400">{{ $brand->products_count }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Against the sticker price (regular_price), not the
                         discount-window effective price — see the comment on
                         ProductList::applyFilters() for why. --}}
                    <div>
                        <label class="block text-[0.7rem] font-semibold uppercase tracking-wider text-ink-500">
                            Price
                        </label>
                        <div class="mt-2 flex items-center gap-2">
                            <div class="relative flex-1">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-ink-400">€</span>
                                <input
                                    type="number" min="0" step="0.01" inputmode="decimal"
                                    wire:model.live.debounce.400ms="minPrice"
                                    placeholder="Min"
                                    aria-label="Minimum price"
                                    class="w-full rounded-control border border-ink-200 bg-white py-2 pl-6 pr-2 text-sm
                                           transition-colors duration-200 hover:border-ink-300
                                           focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/10"
                                >
                            </div>
                            <span class="text-ink-300" aria-hidden="true">–</span>
                            <div class="relative flex-1">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-ink-400">€</span>
                                <input
                                    type="number" min="0" step="0.01" inputmode="decimal"
                                    wire:model.live.debounce.400ms="maxPrice"
                                    placeholder="Max"
                                    aria-label="Maximum price"
                                    class="w-full rounded-control border border-ink-200 bg-white py-2 pl-6 pr-2 text-sm
                                           transition-colors duration-200 hover:border-ink-300
                                           focus:border-marine-600 focus:outline-none focus:ring-4 focus:ring-marine-600/10"
                                >
                            </div>
                        </div>
                    </div>

                    {{-- A product with no approved reviews is always shown,
                         at any tier — ProductList::applyFilters() OR's a
                         whereDoesntHave alongside the average-rating check. --}}
                    <fieldset>
                        <legend class="text-[0.7rem] font-semibold uppercase tracking-wider text-ink-500">
                            Rating
                        </legend>
                        <div class="mt-2.5 space-y-2">
                            <label class="flex cursor-pointer items-center gap-2.5 text-sm text-ink-700">
                                <input type="radio" wire:model.live="minRating" value=""
                                       class="h-4 w-4 border-ink-300 text-marine-600
                                              focus:ring-4 focus:ring-marine-600/20">
                                Any rating
                            </label>
                            @foreach (\App\Livewire\Catalogue\ProductList::RATING_TIERS as $tier)
                                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-ink-700">
                                    <input type="radio" wire:model.live="minRating" value="{{ $tier }}"
                                           class="h-4 w-4 border-ink-300 text-marine-600
                                                  focus:ring-4 focus:ring-marine-600/20">
                                    {{ $tier }}★ & up
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>
            </form>

            <main>
                {{-- Category-specific facets, as hover-triggered dropdowns —
                     above the grid rather than in the sidebar, since these
                     are the filters a shopper who has already picked a
                     category cares about most. Empty until a category is
                     picked, and then scoped to what that category (or an
                     ancestor of it) allows — Colour and Size mean nothing
                     across a catalogue that also holds power tools.
                     ProductList::attributeFacets(). Each value's own count
                     is computed against every filter except its own
                     attribute (ProductList::applyFilters()'s
                     $skipAttributeId), so picking Denim narrows Colour and
                     Size to what Denim actually has, without a selected
                     Colour narrowing its own remaining options to zero.

                     Minimal by design: one small trigger per attribute
                     rather than every value shown at once. Hover (or focus,
                     for keyboard use) opens the panel; the trigger itself
                     carries a count badge once something in it is picked,
                     so the closed state still says what is active. The
                     "active filters" chip row further down is where a
                     selection actually gets removed — this panel is only
                     for adding. --}}
                @if ($this->attributeFacets->isNotEmpty())
                    <div class="mb-6 flex flex-wrap gap-2 border-b border-ink-200 pb-6">
                        @foreach ($this->attributeFacets as $attributeName => $values)
                            @php($attributeId = (string) $values->first()->attribute_id)
                            @php($selectedInGroup = collect($values)->pluck('id')->intersect(array_map('intval', (array) $attributeValueIds)))
                            <div
                                x-data="{ open: false }"
                                x-on:mouseenter="open = true"
                                x-on:mouseleave="open = false"
                                x-on:keydown.escape.window="open = false"
                                class="relative"
                                wire:key="facet-{{ Str::slug($attributeName) }}"
                            >
                                <button
                                    type="button"
                                    x-on:focus="open = true"
                                    x-on:click="open = ! open"
                                    :aria-expanded="open ? 'true' : 'false'"
                                    class="inline-flex items-center gap-1.5 rounded-control border px-3 py-1.5 text-sm font-medium
                                           transition-colors duration-200 focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20
                                           {{ $selectedInGroup->isNotEmpty()
                                                ? 'border-marine-600 bg-marine-50 text-marine-900'
                                                : 'border-ink-200 bg-white text-ink-700 hover:border-marine-600/50' }}"
                                >
                                    {{ $attributeName }}
                                    @if ($selectedInGroup->isNotEmpty())
                                        <span class="grid h-4 w-4 place-items-center rounded-full bg-marine-600 text-[0.65rem] font-semibold text-white">
                                            {{ $selectedInGroup->count() }}
                                        </span>
                                    @endif
                                    <svg class="h-3.5 w-3.5 text-ink-400 transition-transform duration-200" :class="open && 'rotate-180'"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>

                                {{-- pt-1.5, not the panel-content's own
                                     margin-top: a gap between the trigger's
                                     bottom edge and the panel's top edge is a
                                     dead zone the mouse crosses on the way
                                     down, and mouseleave on the wrapping
                                     .relative fires the instant the cursor
                                     leaves the trigger's own box — the panel
                                     is `absolute` and out of flow, so the
                                     wrapper never grows to cover that gap.
                                     Padding keeps the gap inside this
                                     element's own hoverable box instead. --}}
                                <div
                                    x-show="open"
                                    x-cloak
                                    x-on:focusout="if (! $el.contains($event.relatedTarget)) open = false"
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="absolute left-0 top-full z-20 w-52 pt-1.5"
                                >
                                    <div class="rounded-card border border-ink-200 bg-white p-1.5 shadow-xl shadow-ink-900/10">
                                        @foreach ($values as $value)
                                            @php($checked = in_array($value->id, array_map('intval', (array) $attributeValueIds), true))
                                            <button
                                                type="button"
                                                wire:key="facet-value-{{ $value->id }}"
                                                wire:click="toggleAttributeValue({{ $value->id }})"
                                                aria-pressed="{{ $checked ? 'true' : 'false' }}"
                                                class="flex w-full items-center justify-between gap-2 rounded-control px-2.5 py-1.5 text-left text-sm
                                                       transition-colors duration-150 focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20
                                                       {{ $checked ? 'bg-marine-50 font-medium text-marine-900' : 'text-ink-700 hover:bg-ink-50' }}"
                                            >
                                                <span class="flex items-center gap-2">
                                                    <svg class="h-3.5 w-3.5 shrink-0 {{ $checked ? 'text-marine-600' : 'text-transparent' }}"
                                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                    </svg>
                                                    {{ $value->value }}
                                                </span>
                                                <span class="text-xs text-ink-400">{{ $value->products_count }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

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

                    {{-- Staff-only, checked by the same isDemoModeAvailable()
                         setSortOrder() itself re-checks — the button and the
                         guard cannot disagree. Not in SORTS: a public const
                         can't be conditional on the viewer, so this option
                         lives here instead of in the @foreach above. --}}
                    @if ($this->isDemoModeAvailable())
                        @php($demoActive = $sortBy === \App\Livewire\Catalogue\ProductList::DEMO_SORT_KEY)
                        <button type="button"
                                wire:click="setSortOrder('{{ \App\Livewire\Catalogue\ProductList::DEMO_SORT_KEY }}')"
                                @if ($demoActive) aria-current="true" @endif
                                class="inline-flex items-center gap-1 rounded-control border border-dashed border-ink-300
                                       px-3 py-1.5 text-sm font-medium transition-all duration-200
                                       focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/20
                                       {{ $demoActive ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">
                            Demo order
                            <span class="text-[0.65rem] uppercase tracking-wide opacity-60">Staff</span>
                        </button>
                    @endif

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
                            @php($price = $this->price($product))
                            @php($stock = $this->availableStock($product))
                            @php($rating = $product->rating_avg ? round((float) $product->rating_avg, 1) : null)

                            <article
                                wire:key="product-{{ $product->id }}"
                                style="animation-delay: {{ min($index * 40, 320) }}ms"
                                class="group animate-card-in flex flex-col overflow-hidden rounded-card border
                                       border-ink-200 bg-white transition-all duration-300
                                       hover:-translate-y-1.5 hover:border-marine-600/50 hover:shadow-xl hover:shadow-ink-900/10"
                            >
                                <a href="/products/{{ $product->slug }}" class="relative block aspect-square overflow-hidden bg-ink-50">
                                    {{-- One flourish, on the core action. --}}
                                    <span aria-hidden="true"
                                          class="pointer-events-none absolute inset-y-0 -left-1/3 z-10 w-1/3 -skew-x-12
                                                 bg-gradient-to-r from-transparent via-white/45 to-transparent
                                                 opacity-0 group-hover:opacity-100 group-hover:animate-shine"></span>
                                    @if ($image)
                                        <img src="{{ $image->servableUrl() }}"
                                             alt="{{ $image->alt_text ?? $product->name }}" loading="lazy"
                                             class="h-full w-full object-cover transition-transform duration-500
                                                    ease-[cubic-bezier(0.25,1,0.5,1)] group-hover:scale-[1.06]">
                                    @else
                                        <img src="{{ asset('images/default-product.png') }}"
                                             alt="{{ $product->name }} — no photo available" loading="lazy"
                                             class="h-full w-full object-cover">
                                    @endif

                                    {{-- Badges: saving first, then scarcity. Both are facts
                                         from the data, never decoration. --}}
                                    <div class="absolute left-2.5 top-2.5 flex flex-col items-start gap-1.5">
                                        @if ($price->percent > 0)
                                            <span class="rounded bg-marine-600 px-1.5 py-0.5 text-[0.65rem] font-bold text-white">
                                                −{{ $price->percent }}%
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

                                    {{-- Demo-mode-only: which showcase case this card is.
                                         Gated on the sort actually being active, not merely
                                         on the label existing — a customer paging through the
                                         same 13 products under a normal sort must never see
                                         this, and isDemoModeAvailable() is what setSortOrder()
                                         itself re-checks, so the two cannot disagree. --}}
                                    @if ($sortBy === \App\Livewire\Catalogue\ProductList::DEMO_SORT_KEY && $this->isDemoModeAvailable() && $product->demo_case_label)
                                        <span
                                            class="absolute right-2.5 top-2.5 max-w-[calc(100%-1.25rem)] truncate rounded
                                                   bg-violet-600 px-1.5 py-0.5 text-[0.65rem] font-semibold text-white"
                                            title="{{ $product->demo_case_label }}"
                                        >
                                            #{{ $product->demo_case_order }} {{ $product->demo_case_label }}
                                        </span>
                                    @endif
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
                                            @if ($price->onSale)
                                                <span class="text-base font-bold tabular-nums text-marine-700">
                                                    €{{ number_format((float) $price->current, 2) }}
                                                </span>
                                                <span class="text-xs tabular-nums text-ink-400 line-through">
                                                    €{{ number_format((float) $price->regular, 2) }}
                                                </span>
                                            @else
                                                <span class="text-base font-bold tabular-nums">
                                                    €{{ number_format((float) $price->current, 2) }}
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
