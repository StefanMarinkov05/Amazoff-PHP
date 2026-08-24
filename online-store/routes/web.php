<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductList;
use App\Livewire\Catalogue\ProductDetails;
use Illuminate\Support\Facades\Route;

Route::get('/catalogue', ProductList::class);
Route::get('/products/{product:slug}', ProductDetails::class);
