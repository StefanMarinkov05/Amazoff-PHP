<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Amazoff')
            // Htmlable, not a plain string: resources/views/filament/components/
            // brand-logo.blade.php renders the same two-tone wordmark as the
            // storefront header, echoing public/images/logo.png. A bare string
            // here would be treated as an <img src>, which can't produce the
            // styled text.
            ->brandLogo(fn () => new HtmlString(view('filament.components.brand-logo')->render()))
            ->brandLogoHeight('2rem')
            // No ->login() here, deliberately. Filament's panel login and the
            // storefront's /login authenticated the same `web` guard, so a
            // second password form was a second surface to audit for nothing:
            // a staff member signing in at /login is already authenticated for
            // the panel, and canAccessPanel() is what actually gates it.
            // Filament redirects a guest to the `login` named route instead.
            ->colors([
                'primary' => Color::Amber,
            ])
            // Off by default in Filament v4. A Create/Edit page's record save
            // and its relationship sync (CouponForm's products/
            // productCategories, ProductForm's attributes, RoleForm's
            // permission checklists) are otherwise separate auto-committed
            // statements — a detach then a re-attach — leaving a real,
            // if narrow, window where a concurrent read sees neither the old
            // nor the new state. Actions already open their own nested
            // DB::transaction() regardless (savepoint semantics, outermost
            // boundary commits — docs/reference/actions.md), so this adds no
            // conflict there.
            ->databaseTransactions()
            // A staff member's only other way out is typing the URL by hand —
            // sort(-2) puts it before "Profile" (-1) and "Sign out"
            // (PHP_INT_MAX), the two Filament registers itself.
            ->userMenuItems([
                MenuItem::make()
                    ->label('View site')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url('/', shouldOpenInNewTab: true)
                    ->sort(-2),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
