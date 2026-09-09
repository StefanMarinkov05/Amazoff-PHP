<div class="mx-auto w-full max-w-4xl px-4 py-12 sm:px-6">

    <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Your wishlist</h1>
    <p class="mt-2 text-sm text-ink-500">
        Products you saved for later.
    </p>

    @if ($items->isEmpty())
        <p class="mt-8 rounded-control border border-ink-200 bg-white px-4 py-6 text-sm text-ink-500">
            Nothing saved yet.
            <a href="/catalogue" wire:navigate
               class="font-medium text-marine-700 underline-offset-4 hover:underline">
                Browse the catalogue
            </a>
        </p>
    @else
        <div class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($items as $item)
                @php($product = $item->product)
                @continue(! $product)
                @php($image = $product->productImages->firstWhere('is_main', true) ?? $product->productImages->first())

                <article wire:key="wishlist-{{ $item->id }}"
                         class="flex flex-col overflow-hidden rounded-card border border-ink-200 bg-white">
                    <a href="/products/{{ $product->slug }}" wire:navigate class="block aspect-square overflow-hidden bg-ink-50">
                        @if ($image)
                            <img src="{{ $image->servableUrl() }}" alt="{{ $image->alt_text ?? $product->name }}"
                                 loading="lazy" class="h-full w-full object-cover">
                        @else
                            <img src="{{ asset('images/default-product.png') }}"
                                 alt="{{ $product->name }} — no photo available" loading="lazy"
                                 class="h-full w-full object-cover">
                        @endif
                    </a>

                    <div class="flex flex-1 flex-col p-3">
                        @if ($product->brand)
                            <p class="text-[0.65rem] font-medium uppercase tracking-[0.1em] text-ink-400">
                                {{ $product->brand->name }}
                            </p>
                        @endif

                        <h2 class="mt-1 text-sm font-medium leading-snug">
                            <a href="/products/{{ $product->slug }}" wire:navigate class="line-clamp-2 hover:text-marine-700">
                                {{ $product->name }}
                            </a>
                        </h2>

                        <button wire:click="remove({{ $item->id }})" type="button"
                                class="mt-auto pt-3 text-left text-xs font-medium text-red-600 hover:text-red-700">
                            Remove
                        </button>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $items->links() }}
        </div>
    @endif
</div>
