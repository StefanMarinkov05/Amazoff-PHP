<?php

namespace App\Livewire\Catalogue;

use Livewire\Attributes\Url;
use Livewire\Attributes\Computed;
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
    #[Url] public string $sortBy = 'created_at';
    #[Url] public string $sortDir = 'desc';

    public function updated(string $property)
    {
        if ($property !== 'page'){
            $this->resetPage();
        }
    }

    public function setSortOrder(string $column){
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    #[Computed]
    public function categories()
    {
        return ProductCategory::orderBy('name')->get();
    }

    public function render()
    {
        return view('livewire.catalogue.product-list', [
            'products' => Product::query()
            ->where('is_available', true)
            ->with(['productImages', 'brand'])
            ->when($this->search, function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%');
            })
            ->when($this->categoryId, function ($query) {
                $query->where('product_category_id', $this->categoryId);
            })
            ->when($this->brandId, function ($query) {
                $query->where('brand_id', $this->brandId);
            })
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(10),
        ]);
    }
}
