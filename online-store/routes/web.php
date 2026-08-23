<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductList;
use Illuminate\Support\Facades\Route;

Route::get('/catalogue', ProductList::class);
