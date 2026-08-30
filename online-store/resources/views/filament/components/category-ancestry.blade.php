{{--
    A category's place in the tree, root first, with the category itself
    emphasised. Rendered on the edit page so the hierarchy is visible without
    opening each parent in turn.

    $ancestry is a list<ProductCategory> from
    App\Support\ResolveCategoryFamily::ancestryOf() — always at least one
    entry (the category itself), so there is no empty state to handle.
--}}
<div class="fi-ancestry flex flex-wrap items-center gap-x-1 gap-y-2 text-sm">
    @foreach ($ancestry as $index => $node)
        @if (! $loop->first)
            <span class="text-gray-400 dark:text-gray-500" aria-hidden="true">/</span>
        @endif

        @if ($loop->last)
            {{-- The category being edited. aria-current marks it for a screen
                 reader too, not only by colour. --}}
            <span
                aria-current="page"
                class="rounded-md bg-amber-100 px-2 py-1 font-semibold text-amber-900 ring-1 ring-amber-300 dark:bg-amber-400/20 dark:text-amber-200 dark:ring-amber-400/40"
            >
                {{ $node->name }}
            </span>
        @else
            <a
                href="{{ \App\Filament\Resources\ProductCategories\ProductCategoryResource::getUrl('edit', ['record' => $node]) }}"
                class="rounded-md px-2 py-1 text-gray-600 underline-offset-2 hover:bg-gray-100 hover:underline dark:text-gray-300 dark:hover:bg-white/5"
            >
                {{ $node->name }}
            </a>
        @endif
    @endforeach
</div>
