<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Livewire\Catalogue\ProductList;

Route::get('/catalogue', ProductList::class);
