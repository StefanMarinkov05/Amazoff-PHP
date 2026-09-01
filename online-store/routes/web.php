<?php

declare(strict_types=1);

use App\Livewire\Auth\ChangePassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Cart\CartPage;
use App\Livewire\Catalogue\ProductDetails;
use App\Livewire\Catalogue\ProductList;
use App\Livewire\Checkout\CheckoutPage;
use App\Livewire\Checkout\OrderConfirmation;
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
Route::get('/cart', CartPage::class)->name('cart');

/*
 * Checkout is open to guests (§37 #6) and to signed-in customers (§37 #7) —
 * the same component either way; the only difference is prefilled details
 * and whether the order carries a user_id.
 *
 * The confirmation route takes an id but is NOT a public lookup:
 * OrderConfirmation refuses anything the visitor neither owns nor just
 * placed in this session, because serial numbers are sequential and a bare
 * findOrFail would enumerate every customer's address.
 */
Route::get('/checkout', CheckoutPage::class)->name('checkout');
Route::get('/checkout/confirmation/{order}', OrderConfirmation::class)->name('checkout.confirmation');
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
