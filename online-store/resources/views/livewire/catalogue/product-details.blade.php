@php
    use App\Enums\AttributeInputType;

    $price = $this->price;
    $gallery = $this->gallery;
    $main = $gallery[$this->imageIndex] ?? $gallery->first();
    $rating = $this->reviews->avg('rating');
@endphp

<div class="bg-ink-50">
    <div class="mx-auto max-w-6xl px-4 py-6 lg:py-10">

        {{-- ── Breadcrumb ─────────────────────────────────────────────── --}}
        <nav aria-label="Breadcrumb" class="mb-6 text-xs text-ink-400">
            <ol class="flex flex-wrap items-center gap-1.5">
                <li><a href="/catalogue" class="transition-colors hover:text-marine-700">Catalogue</a></li>

                @foreach ($this->breadcrumb as $crumb)
                    <li aria-hidden="true" class="text-ink-300">/</li>
                    <li wire:key="crumb-{{ $crumb->id }}">
                        <a href="/catalogue?category={{ $crumb->slug }}"
                           class="transition-colors hover:text-marine-700">{{ $crumb->name }}</a>
                    </li>
                @endforeach

                <li aria-hidden="true" class="text-ink-300">/</li>
                <li class="font-medium text-ink-600" aria-current="page">{{ $this->product->name }}</li>
            </ol>
        </nav>

        <div class="grid gap-8 md:grid-cols-2 md:gap-10 lg:gap-12">

            {{-- ── Gallery ────────────────────────────────────────────── --}}
            <div class="mx-auto w-full max-w-md md:mx-0 md:max-w-none md:sticky md:top-24 md:self-start">
                <div class="group relative aspect-square overflow-hidden rounded-card border border-ink-200 bg-white">
                    @if ($main)
                        {{-- wire:key on the image itself: without it Livewire
                             reuses the previous <img> node and the browser
                             keeps showing the old bitmap after a variation
                             change, which reads as "the click did nothing". --}}
                        <img
                            wire:key="main-image-{{ $main->id }}"
                            src="{{ $main->servableUrl() }}"
                            alt="{{ $main->alt_text ?? $this->product->name }}"
                            class="h-full w-full object-cover transition-transform duration-500
                                   ease-[cubic-bezier(0.25,1,0.5,1)] group-hover:scale-[1.04]"
                        >
                    @else
                        <img
                            src="{{ asset('images/default-product.png') }}"
                            alt="{{ $this->product->name }} — no photo available"
                            class="h-full w-full object-cover"
                        >
                    @endif

                    @if ($price->onSale)
                        <span class="absolute left-3 top-3 rounded bg-marine-600 px-2 py-1 text-xs font-bold text-white">
                            −{{ $price->percent }}%
                        </span>
                    @endif

                    {{-- Arrows, only when there is somewhere to go. Fade in on
                         hover at pointer widths; always visible on touch,
                         where there is no hover to reveal them. --}}
                    @if ($gallery->count() > 1)
                        <button
                            type="button"
                            wire:click="previousImage"
                            aria-label="Previous image"
                            class="absolute left-2 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center
                                   rounded-full bg-white/85 text-ink-700 shadow-md backdrop-blur-sm
                                   transition-all duration-200 hover:bg-white hover:text-marine-700
                                   focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/25
                                   md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                            </svg>
                        </button>

                        <button
                            type="button"
                            wire:click="nextImage"
                            aria-label="Next image"
                            class="absolute right-2 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center
                                   rounded-full bg-white/85 text-ink-700 shadow-md backdrop-blur-sm
                                   transition-all duration-200 hover:bg-white hover:text-marine-700
                                   focus:outline-none focus-visible:ring-4 focus-visible:ring-marine-600/25
                                   md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                        </button>

                        {{-- Position counter: tells you a gallery exists even
                             before you find the arrows. --}}
                        <span class="absolute bottom-2 right-2 rounded-full bg-ink-900/70 px-2 py-0.5
                                     text-[0.7rem] font-medium tabular-nums text-white backdrop-blur-sm">
                            {{ $this->imageIndex + 1 }} / {{ $gallery->count() }}
                        </span>
                    @endif
                </div>

                {{-- Thumbnails. Only when there is a choice to make. --}}
                @if ($gallery->count() > 1)
                    <div class="mt-3 flex gap-2.5 overflow-x-auto pb-1">
                        @foreach ($gallery as $index => $image)
                            <button
                                type="button"
                                wire:key="thumb-{{ $image->id }}"
                                wire:click="setImage({{ $index }})"
                                aria-label="View image {{ $index + 1 }}"
                                @if ($index === $this->imageIndex) aria-current="true" @endif
                                class="relative h-16 w-16 shrink-0 cursor-pointer overflow-hidden rounded-control border-2
                                       transition-all duration-200 focus:outline-none
                                       focus-visible:ring-4 focus-visible:ring-marine-600/25
                                       {{ $index === $this->imageIndex
                                            ? 'border-marine-600'
                                            : 'border-ink-200 opacity-70 hover:opacity-100 hover:border-ink-300' }}"
                            >
                                <img src="{{ $image->servableUrl() }}"
                                     alt="{{ $image->alt_text ?? $this->product->name }}"
                                     loading="lazy" class="h-full w-full object-cover">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- ── Details ────────────────────────────────────────────── --}}
            <div>
                @if ($this->brand)
                    <p class="text-xs font-medium uppercase tracking-[0.12em] text-ink-400">
                        {{ $this->brand->name }}
                    </p>
                @endif

                <h1 class="mt-1.5 text-2xl font-semibold leading-tight text-ink-900 lg:text-3xl">
                    {{ $this->product->name }}
                </h1>

                {{-- Rating, only when there is one. An empty five-star row
                     reads as zero stars rather than as no data. --}}
                @if ($rating)
                    <div class="mt-2.5 flex items-center gap-2">
                        <div class="flex" aria-hidden="true">
                            @foreach (range(1, 5) as $star)
                                <svg class="h-4 w-4 {{ $star <= round($rating) ? 'text-amber-400' : 'text-ink-200' }}"
                                     fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M10 15.27 16.18 19l-1.64-7.03L20 7.24l-7.19-.61L10 0 7.19 6.63 0 7.24l5.46 4.73L3.82 19z"/>
                                </svg>
                            @endforeach
                        </div>
                        <span class="text-sm text-ink-500">
                            {{ number_format($rating, 1) }}
                            <span class="text-ink-400">({{ $this->reviews->count() }})</span>
                        </span>
                    </div>
                @endif

                @if ($this->product->short_description)
                    <p class="mt-4 text-sm leading-relaxed text-ink-600">
                        {{ $this->product->short_description }}
                    </p>
                @endif

                {{-- Price. Same ProductPrice shape the catalogue card uses. --}}
                <div class="mt-6 border-t border-ink-200 pt-5">
                    <div class="flex items-baseline gap-2.5">
                        <span class="text-3xl font-bold tabular-nums {{ $price->onSale ? 'text-marine-700' : 'text-ink-900' }}">
                            €{{ number_format((float) $price->current, 2) }}
                        </span>

                        @if ($price->onSale)
                            <span class="text-base tabular-nums text-ink-400 line-through">
                                €{{ number_format((float) $price->regular, 2) }}
                            </span>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-ink-400">VAT included</p>
                </div>

                {{-- ── Variation picker ───────────────────────────────── --}}
                @foreach ($this->attributeGroups as $attributeId => $group)
                    @php($isColour = $group['attribute']->input_type === AttributeInputType::Color)

                    <div class="mt-6" wire:key="attribute-{{ $attributeId }}">
                        <p class="text-xs font-medium uppercase tracking-[0.1em] text-ink-400">
                            {{ $group['attribute']->name }}
                            @php($selected = collect($group['values'])->first(fn ($v) => $this->valueIsSelected($attributeId, $v->id)))
                            @if ($selected)
                                <span class="ml-1 normal-case tracking-normal text-ink-600">— {{ $selected->value }}</span>
                            @endif
                        </p>

                        <div class="mt-2.5 flex flex-wrap gap-2">
                            @foreach ($group['values'] as $value)
                                @php($available = $this->valueIsAvailable($attributeId, $value->id))
                                @php($chosen = $this->valueIsSelected($attributeId, $value->id))

                                <button
                                    type="button"
                                    wire:key="value-{{ $value->id }}"
                                    wire:click="selectValue({{ $attributeId }}, {{ $value->id }})"
                                    @disabled(! $available)
                                    title="{{ $value->value }}{{ $available ? '' : ' — unavailable' }}"
                                    class="relative transition-all duration-200 focus:outline-none
                                           focus-visible:ring-4 focus-visible:ring-marine-600/25
                                           disabled:cursor-not-allowed disabled:opacity-35
                                           {{ $isColour && $value->color_hex
                                                ? 'h-9 w-9 rounded-full border-2 '.($chosen ? 'border-marine-600 ring-2 ring-marine-600/30 ring-offset-2' : 'border-ink-200 hover:border-ink-400')
                                                : 'rounded-control border px-3.5 py-2 text-sm '.($chosen ? 'border-marine-600 bg-marine-600 font-medium text-white' : 'border-ink-200 bg-white hover:border-marine-600') }}"
                                    @if ($isColour && $value->color_hex) style="background-color: {{ $value->color_hex }}" @endif
                                >
                                    @unless ($isColour && $value->color_hex)
                                        {{ $value->value }}
                                    @else
                                        <span class="sr-only">{{ $value->value }}</span>
                                    @endunless
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                {{-- ── Availability ───────────────────────────────────── --}}
                <div class="mt-6 flex items-center gap-2 text-sm">
                    @if ($this->stock === 0)
                        <span class="inline-flex h-2 w-2 rounded-full bg-ink-300"></span>
                        <span class="font-medium text-ink-500">Out of stock</span>
                    @elseif ($this->stock <= 3)
                        <span class="inline-flex h-2 w-2 animate-pulse rounded-full bg-amber-500"></span>
                        <span class="font-medium text-amber-700">Only {{ $this->stock }} left</span>
                    @else
                        <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                        <span class="font-medium text-emerald-700">In stock</span>
                    @endif

                    @if ($this->variation?->sku)
                        <span class="ml-auto font-mono text-xs text-ink-400">{{ $this->variation->sku }}</span>
                    @endif
                </div>

                {{-- ── Add to cart ──────────────────────────────────── --}}
                <div class="mt-5">
                    @if (session('success'))
                        <div class="mb-3 flex items-center gap-2 rounded-control border border-emerald-200
                                    bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                 stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                            {{ session('success') }}
                        </div>
                    @endif

                    @error('cart')
                        <div role="alert"
                             class="mb-3 rounded-control border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                            {{ $message }}
                        </div>
                    @enderror

                    @php($minimumOrderQuantity = max(1, $this->product->min_order_quantity))
                    {{-- Real stock, but not enough to meet the minimum order
                         — a different state from "Out of stock" (stock=0):
                         nothing here is a bug, AddToCart already refuses
                         this cleanly server-side (InsufficientStockException,
                         never a crash, verified against a raw request that
                         bypasses this exact warning). This is the UI closing
                         a gap where the stepper used to default to a
                         quantity — the minimum — that was never actually
                         purchasable, with nothing on the page saying so
                         before the click. --}}
                    @php($belowMinimumStock = $this->stock > 0 && $this->stock < $minimumOrderQuantity)

                    <div class="flex gap-3">
                        <div class="flex items-center rounded-control border border-ink-200 bg-white">
                            <button
                                type="button"
                                wire:click="$set('quantity', {{ max($minimumOrderQuantity, (int) $this->quantity - 1) }})"
                                @disabled((int) $this->quantity <= $minimumOrderQuantity)
                                aria-label="Decrease quantity"
                                class="grid h-11 w-10 place-items-center text-lg text-ink-500 transition-colors
                                       hover:text-marine-700 disabled:cursor-not-allowed disabled:opacity-40"
                            >−</button>

                            <input
                                type="number"
                                min="{{ $minimumOrderQuantity }}"
                                max="{{ $this->stock }}"
                                inputmode="numeric"
                                wire:model.live.debounce.400ms="quantity"
                                aria-label="Quantity"
                                class="h-11 w-12 border-0 bg-transparent p-0 text-center text-sm font-medium
                                       tabular-nums text-ink-900 focus:outline-none
                                       [appearance:textfield]
                                       [&::-webkit-inner-spin-button]:appearance-none
                                       [&::-webkit-outer-spin-button]:appearance-none"
                            >

                            <button
                                type="button"
                                wire:click="$set('quantity', {{ (int) $this->quantity + 1 }})"
                                aria-label="Increase quantity"
                                class="grid h-11 w-10 place-items-center text-lg text-ink-500 transition-colors
                                       hover:text-marine-700"
                            >+</button>
                        </div>

                        <button
                            type="button"
                            wire:click="addToCart"
                            wire:loading.attr="disabled"
                            wire:target="addToCart"
                            @disabled($this->stock === 0 || $belowMinimumStock || $this->variation === null)
                            class="flex h-11 flex-1 items-center justify-center gap-2 rounded-control
                                   bg-ink-900 px-5 text-sm font-semibold text-white transition-all duration-200
                                   hover:bg-marine-700 focus:outline-none focus-visible:ring-4
                                   focus-visible:ring-marine-600/25
                                   disabled:cursor-not-allowed disabled:bg-ink-300"
                        >
                            {{-- The Action opens a transaction, so this is not
                                 instant on a slow connection. --}}
                            <svg wire:loading wire:target="addToCart"
                                 class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor"
                                      d="M4 12a8 8 0 0 1 8-8V0C5.4 0 0 5.4 0 12h4z"/>
                            </svg>

                            <span wire:loading.remove wire:target="addToCart">
                                @if ($this->stock === 0)
                                    Out of stock
                                @elseif ($belowMinimumStock)
                                    Not enough stock
                                @else
                                    Add to cart
                                @endif
                            </span>
                            <span wire:loading wire:target="addToCart">Adding…</span>
                        </button>
                    </div>

                    @if ($belowMinimumStock)
                        <p role="alert" class="mt-2 flex items-start gap-1.5 text-xs font-medium text-amber-700">
                            <svg class="mt-px h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24"
                                 stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M12 9v3.75m0 3.75h.008v.008H12v-.008ZM21.75 12a9.75 9.75 0 1 1-19.5 0 9.75 9.75 0 0 1 19.5 0Z" />
                            </svg>
                            Only {{ $this->stock }} in stock — below the minimum order of {{ $minimumOrderQuantity }}
                        </p>
                    @elseif ($minimumOrderQuantity > 1)
                        <p class="mt-2 text-xs text-ink-500">
                            Minimum order: {{ $minimumOrderQuantity }}
                        </p>
                    @endif
                </div>

                {{-- ── Description ────────────────────────────────────── --}}
                @if ($this->product->description)
                    <div class="mt-8 border-t border-ink-200 pt-6">
                        <h2 class="text-sm font-semibold text-ink-900">Description</h2>
                        <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-ink-600">
                            {{ $this->product->description }}
                        </p>
                    </div>
                @endif

                {{-- ── Product details ────────────────────────────────────
                     Descriptive attribute values (attribute_value_product):
                     facts true of every variation, grouped by attribute.
                     Rendered as links into the catalogue filter rather than
                     plain text — being a controlled vocabulary rather than
                     free-text prose is the whole reason this pivot exists,
                     and a value the customer cannot act on wastes that.
                --}}
                @php($productDetails = $this->product->descriptiveAttributeValues->groupBy(fn ($v) => $v->attribute->name))
                @if ($productDetails->isNotEmpty())
                    <div class="mt-8 border-t border-ink-200 pt-6">
                        <h2 class="text-sm font-semibold text-ink-900">Product details</h2>
                        <dl class="mt-3 space-y-3">
                            @foreach ($productDetails as $attributeName => $values)
                                <div wire:key="detail-{{ Str::slug($attributeName) }}" class="text-sm">
                                    <dt class="text-ink-500">{{ $attributeName }}</dt>
                                    <dd class="mt-1.5 flex flex-wrap gap-1.5">
                                        @foreach ($values as $value)
                                            <a
                                                {{-- The /catalogue route is unnamed (routes/web.php), so
                                                     a literal path rather than route() — the same thing
                                                     the redirects there already do. --}}
                                                href="{{ '/catalogue?'.http_build_query(array_filter([
                                                    'category' => $this->product->productCategory?->slug,
                                                    'attributeValueIds' => [$value->id],
                                                ])) }}"
                                                class="inline-flex items-center rounded-full border border-ink-200 bg-white
                                                       px-2.5 py-1 text-xs font-medium text-ink-700 transition-colors
                                                       duration-200 hover:border-marine-600/50 hover:bg-marine-50
                                                       hover:text-marine-900"
                                            >
                                                {{ $value->value }}
                                            </a>
                                        @endforeach
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif

                {{-- ── Specifications ─────────────────────────────────── --}}
                @if ($this->product->productSpecifications->isNotEmpty())
                    <div class="mt-8 border-t border-ink-200 pt-6">
                        <h2 class="text-sm font-semibold text-ink-900">Specifications</h2>
                        <dl class="mt-3 overflow-hidden rounded-card border border-ink-200">
                            @foreach ($this->product->productSpecifications->sortBy('sort_order') as $spec)
                                <div wire:key="spec-{{ $spec->id }}"
                                     class="flex gap-4 px-4 py-2.5 text-sm odd:bg-white even:bg-ink-50">
                                    <dt class="w-2/5 shrink-0 text-ink-500">{{ $spec->name }}</dt>
                                    <dd class="font-medium text-ink-800">{{ $spec->value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif
            </div>
        </div>

        {{-- ── Reviews ────────────────────────────────────────────────── --}}
        <section class="mt-12 border-t border-ink-200 pt-8">
            <h2 class="text-lg font-semibold text-ink-900">
                Reviews
                @if ($this->reviews->isNotEmpty())
                    <span class="ml-1 text-sm font-normal text-ink-400">({{ $this->reviews->count() }})</span>
                @endif
            </h2>

            @forelse ($this->reviews as $review)
                <article wire:key="review-{{ $review->id }}"
                         class="mt-5 rounded-card border border-ink-200 bg-white p-4">
                    <div class="flex items-center gap-2.5">
                        <div class="flex" aria-hidden="true">
                            @foreach (range(1, 5) as $star)
                                <svg class="h-3.5 w-3.5 {{ $star <= $review->rating ? 'text-amber-400' : 'text-ink-200' }}"
                                     fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M10 15.27 16.18 19l-1.64-7.03L20 7.24l-7.19-.61L10 0 7.19 6.63 0 7.24l5.46 4.73L3.82 19z"/>
                                </svg>
                            @endforeach
                        </div>
                        <span class="sr-only">{{ $review->rating }} out of 5</span>

                        <span class="text-sm font-medium text-ink-800">
                            {{ $review->user?->name ?? $review->author_name ?? 'Anonymous' }}
                        </span>

                        <span class="ml-auto text-xs text-ink-400">
                            {{ $review->created_at?->diffForHumans() }}
                        </span>
                    </div>

                    {{-- Escaped, never {!! !!}: a review body is user input and
                         is only moderated, not sanitised. --}}
                    <p class="mt-2.5 text-sm leading-relaxed text-ink-600">{{ $review->body }}</p>
                </article>
            @empty
                <p class="mt-4 rounded-card border border-dashed border-ink-200 px-4 py-8 text-center text-sm text-ink-400">
                    No reviews yet.
                </p>
            @endforelse
        </section>
    </div>
</div>
