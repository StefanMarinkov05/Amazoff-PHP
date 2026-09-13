<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Policies\RolePolicy;
use App\Support\Courier\CourierManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Stripe\StripeClient;
use Stripe\Util\ApiVersion;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Filament allows `style` through so its editor's colours and image
        // sizing survive, and Symfony's sanitizer does not read the CSS
        // inside it — `position: fixed` and `background: url(...)` both pass.
        // Dropped rather than filtered because nothing in `ArticleForm`'s
        // toolbar emits inline style. Global on purpose: the panel and the
        // storefront render the same bodies. ADR-0015.
        $this->app->extend(
            HtmlSanitizerConfig::class,
            fn (HtmlSanitizerConfig $config): HtmlSanitizerConfig => $config
                ->dropAttribute('style', '*'),
        );

        /*
         * Stripe's own SDK client, resolved from the container rather than
         * newed up inside each Action.
         *
         * This is not the interface ADR-0001 refused. There is no
         * App\Contracts\PaymentGateway and no second implementation to swap
         * in — the bound class is Stripe's concrete StripeClient. What the
         * binding buys is a seam for tests to swap a fake through
         * `$this->app->instance()`, so a payment test does not reach the
         * network. The courier is the case that earns a real interface; this
         * is not.
         *
         * Singleton because StripeClient is stateless per-request and
         * building one parses configuration.
         */
        $this->app->singleton(StripeClient::class, function (): StripeClient {
            $secret = config('services.stripe.secret');

            // An empty key produces an authentication failure from Stripe's
            // API rather than anything locally diagnosable, so it is caught
            // here where the cause is still visible.
            if (! is_string($secret) || $secret === '') {
                throw new RuntimeException(
                    'STRIPE_SECRET is not set. See .env.example; the webhook and intent paths both need it.',
                );
            }

            return new StripeClient([
                'api_key' => $secret,
                /*
                 * Pinned rather than left to drift with whatever the SDK
                 * ships as default. Stripe's go-live checklist names this
                 * explicitly for server-side PHP: "set the API version in
                 * the server-side library." Two reasons it matters here
                 * specifically, not just as boilerplate:
                 *
                 * - HandleStripeWebhookEvent reads payload fields
                 *   positionally (amount_received, amount_refunded,
                 *   last_payment_error->message) with no version check of
                 *   its own. A version bump — ours or an endpoint's — that
                 *   renamed or restructured one of those would silently
                 *   change what gets read, not error.
                 * - A webhook endpoint's version is fixed at creation in the
                 *   Stripe dashboard and cannot be changed after — moving
                 *   requires a new endpoint. Pinning the SDK to match is
                 *   what keeps "the version we coded against" and "the
                 *   version Stripe sends" the same fact stated once.
                 *
                 * Value taken from the installed SDK's own ApiVersion::CURRENT
                 * rather than hand-typed, so upgrading stripe/stripe-php is
                 * what moves this forward — not a second place remembering
                 * a version string that can drift from what is actually
                 * installed.
                 */
                'stripe_version' => ApiVersion::CURRENT,
            ]);
        });

        /*
         * The `Courier` facade's accessor and every Action's constructor
         * injection resolve through this one singleton — see
         * App\Support\Courier\CourierManager.
         */
        $this->app->singleton(CourierManager::class, fn (): CourierManager => new CourierManager($this->app));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // administrator holds no permission rows; this is what grants it
        // everything. One line in one provider rather than 108 attached
        // permissions that would drift as the catalogue grows.
        //
        // Returning null rather than false when the role is absent is
        // load-bearing: false here would deny every check for every other
        // user before their policy ran. null means "no opinion", so Laravel
        // continues to spatie's own before-callback and then the policy.
        //
        // The cost is that a policy can no longer deny an administrator
        // anything. A rule like "nobody may delete a paid order" cannot live
        // in a policy once this is registered — it belongs in the Action as a
        // domain invariant, which is where ADR-0004 already puts the question
        // of whether a transition is legal at all.
        Gate::before(fn (User $user, string $ability): ?bool => $user->hasRole('administrator') ? true : null);

        // Every other policy is found by convention — App\Policies\XPolicy for
        // App\Models\X. Role lives in the package's namespace, so convention
        // finds nothing and the model would be ungated: authorization fails
        // open, and this is the model that controls what every role may do.
        Gate::policy(Role::class, RolePolicy::class);

        Table::configureUsing(fn (Table $table): Table => $table->defaultCurrency('eur'));

        // The same override, one layer over: Table and Schema each carry
        // their own copy of HasDefaultDataFormattingSettings, so a table's
        // ->money() column reading 'eur' here said nothing about an
        // infolist's ->money() entry on a *ViewRecord* page — every
        // *Infolist class (Order, Shipment, Return, Payment, Product,
        // Coupon) was silently falling back to Filament's own USD default,
        // showing $ on every euro amount. Table::configureUsing() alone
        // never covered this; Schema needs its own registration.
        Schema::configureUsing(fn (Schema $schema): Schema => $schema->defaultCurrency('eur'));
    }
}
