<?php

namespace App\Livewire\Catalogue;

use Livewire\Component;
use Ramsey\Collection\Collection;
use App\Models\ProductCategory;
use App\Models\Product;
use Livewire\WithPagination;

class ProductList extends Component
{
    use WithPagination;

    #[Url] public string $search = '';
    #[Url] public ?int $categoryId = null;
    #[Url] public ?int $brandId = null;
    #[Url] public string $sort = 'newest';

    public function updated(string $property)
    {
        if ($property !== 'page'){
            $this->resetPage();
        }
    }

    #[Computed]
    public function categories(): Collection
    {
        return ProductCategory::orderBy('name')->get();
    }

    public function render()
    {
        return view('livewire.catalogue.product-list', [
            'products' => Product::query()
            ->where('is_available', true)
            ->with(['productImages', 'brand'])
            // ->when($this->search, fn ($q) => )
            // -when($this->categoryId, fn($q) => )
            ->paginate(10),
        ]);
    }
}
