<div class="mx-auto max-w-7xl p-6">

    {{-- Controls: one per property from step 2. --}}
    <div class="mb-6 flex gap-4">

        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Search products…"
            class="rounded border px-3 py-2"
        >

        <select wire:model.live="categoryId" class="rounded border px-3 py-2">
            <option value="">All categories</option>
            @foreach ($this->categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>

        {{-- YOU: a brand select, same shape, needs a brands() computed --}}
        {{-- YOU: a sort select — plain <option> values, no query needed --}}
    </div>

    {{-- Data: from render()'s array. --}}
    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
        @forelse ($products as $product)
            <article class="rounded border p-4">
                <h2 class="font-medium">{{ $product->name }}</h2>
                {{-- YOU: image, brand, price --}}
            </article>
        @empty
            <p>No products match those filters.</p>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $products->links() }}
    </div>
</div>
