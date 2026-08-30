<?php

declare(strict_types=1);

use App\Livewire\Auth\ChangePassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Catalogue\ProductDetails;
use App\Livewire\Catalogue\ProductList;
use App\Livewire\Contact\ContactForm;
use App\Livewire\Journal\ArticleDetails;
use App\Livewire\Journal\ArticleList;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/catalogue');

Route::get('/catalogue', ProductList::class);
Route::get('/products/{product:slug}', ProductDetails::class);
Route::view('/about', 'pages.about')->name('about');
Route::get('/contact', ContactForm::class)->name('contact');
Route::get('/journal', ArticleList::class)->name('journal');
Route::get('/journal/{article:slug}', ArticleDetails::class);

/*
 * Authentication. Laravel's own guard and session, no starter kit — the
 * implementation standards allow at most one authentication library, and
 * Filament's panel login authenticates through this same guard rather than
 * being a second one.
 */
Route::middleware('guest')->group(function (): void {
    Route::get('/login', Login::class)->name('login');
    Route::get('/register', Register::class)->name('register');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/account/password', ChangePassword::class)->name('password.change');

    // POST, not GET: a GET logout is triggerable by any <img> tag on any page
    // the user visits, which is CSRF by prefetch rather than by form.
    Route::post('/logout', function () {
        Auth::logout();

        session()->invalidate();
        session()->regenerateToken();

        return redirect('/catalogue');
    })->name('logout');
});
