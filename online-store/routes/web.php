<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductDetails;
use App\Livewire\Catalogue\ProductList;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/catalogue');

Route::get('/catalogue', ProductList::class);
Route::get('/products/{product:slug}', ProductDetails::class);
