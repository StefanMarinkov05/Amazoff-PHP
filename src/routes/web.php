<?php

declare(strict_types=1);

use App\Http\Controllers\NewsletterController;
use App\Livewire\Account\DeleteAccount;
use App\Livewire\Account\DownloadData;
use App\Livewire\Account\OrderHistory;
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
use App\Livewire\Orders\TrackOrder;
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

/*
 * Public tracking. Deliberately outside `auth`: a guest who ordered has no
 * session a week later and no account to sign into, so the only workable key
 * is the pair CLAUDE.md's security rules name — order number *and* email,
 * matched in one query and rate-limited. TrackOrder's docblock has the
 * reasoning for both halves.
 */
Route::get('/orders/track', TrackOrder::class)->name('orders.track');

Route::get('/journal', ArticleList::class)->name('journal');
Route::get('/journal/{article:slug}', ArticleDetails::class);

/*
 * Informational pages. Plain views, not Livewire components: they hold no
 * state and run no query, so a component would be pattern-following (ADR-0014,
 * the same reasoning `/about` already follows).
 */
Route::view('/delivery', 'pages.delivery')->name('delivery');
Route::view('/payment-information', 'pages.payment-information')->name('payment-information');
Route::view('/faq', 'pages.faq')->name('faq');
Route::view('/terms', 'pages.terms')->name('terms');
Route::view('/privacy', 'pages.privacy')->name('privacy');
Route::view('/cookies', 'pages.cookies')->name('cookies');

// Consumer Rights Directive Annex I(B) — the model withdrawal form must be
// available whether or not the customer uses the online returns flow.
// Linked from checkout, the order-confirmation email, and the order page.
Route::view('/returns/withdrawal-form', 'pages.returns.withdrawal-form')->name('returns.withdrawal-form');

// Newsletter double opt-in (ePrivacy Art. 13, ADR-0019). The token in the
// path is the whole input — a UNIQUE 64-char column, matched by the Action.
// Not behind `guest` or `auth`: the link is followed from an email client,
// by whoever holds the address, signed in or not.
Route::get('/newsletter/confirm/{token}', [NewsletterController::class, 'confirm'])->name('newsletter.confirm');
Route::get('/newsletter/unsubscribe/{token}', [NewsletterController::class, 'unsubscribe'])->name('newsletter.unsubscribe');

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

    // GDPR Art. 17 self-service erasure (ADR-0019). Requires the current
    // password and a typed confirmation; calls App\Actions\Gdpr\EraseCustomer
    // then flushes the session.
    Route::get('/account/delete', DeleteAccount::class)->name('account.delete');

    // GDPR Art. 15 / 20 — a machine-readable copy of everything held about
    // the account (App\Actions\Gdpr\ExportCustomerData), streamed as JSON.
    Route::get('/account/data', DownloadData::class)->name('account.data');

    // Scoped to auth()->user()->orders() inside the component, never
    // Order::query() — the middleware answers "is anyone signed in", the
    // scoping answers "whose orders are these".
    Route::get('/account/orders', OrderHistory::class)->name('account.orders');

    // POST, not GET: a GET logout is triggerable by any <img> tag on any page
    // the user visits, which is CSRF by prefetch rather than by form.
    Route::post('/logout', function () {
        Auth::logout();

        session()->invalidate();
        session()->regenerateToken();

        return redirect('/catalogue');
    })->name('logout');
});
