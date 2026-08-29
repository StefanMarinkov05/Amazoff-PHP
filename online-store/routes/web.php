<?php

declare(strict_types=1);

use App\Livewire\Catalogue\ProductDetails;
use App\Livewire\Catalogue\ProductList;
use App\Livewire\Contact\ContactForm;
use Illuminate\Support\Facades\Route;

Route::get('/catalogue', ProductList::class);
Route::get('/products/{product:slug}', ProductDetails::class);
Route::view('/about', 'pages.about')->name('about');
Route::get('/contact', ContactForm::class)->name('contact');
