@props(['title', 'subtitle', 'products', 'price'])

{{--
    Trimmed card markup shared by every product row on the home page —
    image, brand, name, price. Deliberately without the catalogue grid's
    wishlist heart, sale/stock badges, and rating stars: those read as
    "browsing a filtered list" affordances, which is what the catalogue is
    for. This is a shop window pointing at four different lists, not a
    fifth copy of the catalogue grid.
--}}
<section>
    <div>
        <h2 class="text-xl font-semibold tracking-tight text-ink-900">{{ $title }}</h2>
        <p class="mt-1 text-sm text-ink-500">{{ $subtitle }}</p>
    </div>

    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($products as $product)
            @php($cardPrice = $price($product))
            @php($image = $product->productImages->firstWhere('is_main', true) ?? $product->productImages->first())

            <a href="/products/{{ $product->slug }}" wire:navigate wire:key="home-{{ $title }}-{{ $product->id }}"
               class="group flex flex-col overflow-hidden rounded-card border border-ink-200 bg-white
                      transition-all duration-300 hover:-translate-y-1 hover:border-marine-600/50 hover:shadow-lg">
                <div class="aspect-square overflow-hidden bg-ink-50">
                    @if ($image)
                        <img src="{{ $image->servableUrl() }}" alt="{{ $image->alt_text ?? $product->name }}"
                             loading="lazy"
                             class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105">
                    @else
                        <img src="{{ asset('images/default-product.png') }}"
                             alt="{{ $product->name }} — no photo available" loading="lazy"
                             class="h-full w-full object-cover">
                    @endif
                </div>

                <div class="flex flex-1 flex-col p-3">
                    @if ($product->brand)
                        <p class="text-[0.65rem] font-medium uppercase tracking-[0.1em] text-ink-400">
                            {{ $product->brand->name }}
                        </p>
                    @endif

                    <h3 class="mt-1 line-clamp-2 text-sm font-medium leading-snug text-ink-900">
                        {{ $product->name }}
                    </h3>

                    <div class="mt-auto flex items-baseline gap-1.5 pt-2">
                        @if ($cardPrice->onSale)
                            <span class="text-sm font-bold tabular-nums text-marine-700">
                                €{{ number_format((float) $cardPrice->current, 2) }}
                            </span>
                            <span class="text-xs tabular-nums text-ink-400 line-through">
                                €{{ number_format((float) $cardPrice->regular, 2) }}
                            </span>
                        @else
                            <span class="text-sm font-bold tabular-nums text-ink-900">
                                €{{ number_format((float) $cardPrice->current, 2) }}
                            </span>
                        @endif
                    </div>
                </div>
            </a>
        @endforeach
    </div>
</section>
