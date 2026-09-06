<?php

declare(strict_types=1);

namespace App\Livewire\Checkout;

use App\Models\Order;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The "thank you" page, and where Stripe's `return_url` lands.
 *
 * ## Why this is not a plain `Order::findOrFail($id)`
 *
 * `orders.serial_number` is sequential, and this route takes an id. A bare
 * lookup would let anyone walk `/checkout/confirmation/1,2,3…` and read every
 * customer's name, address, phone and order contents — the exact
 * enumeration CLAUDE.md names when it says public order tracking needs
 * "order number **and** email". This page has a narrower job than tracking,
 * so it takes a narrower route: you may see an order here only if you own it
 * or you just placed it in this session.
 *
 * A guest's claim expires with their session, which is correct — the
 * confirmation is a one-time view, not a permanent record. A registered
 * customer reaches the same order through their account, scoped to
 * `auth()->user()->orders()`, never `Order::find()`.
 *
 * ## The status shown is the webhook's, not the redirect's
 *
 * Stripe redirects here on a successful card confirmation, but the redirect
 * is not proof of payment — the browser could be closed mid-redirect, and a
 * redirect is trivially forgeable by typing the URL. `payments.status` is
 * only ever written by the webhook (or a COD remittance), so this page reads
 * whatever the row actually says and tells the customer the truth, including
 * "we are still confirming" when the webhook has not landed yet.
 */
#[Layout('components.layouts.app')]
class OrderConfirmation extends Component
{
    /** The session key a just-placed order is remembered under. */
    public const SESSION_KEY = 'checkout.completed_order_id';

    /**
     * The id only, never the model.
     *
     * A `public Order $order` would collide with the route parameter, which
     * is also called `order`: Livewire assigns matching public properties
     * from the parameters *before* `mount()` runs, so the raw int would be
     * assigned to an `Order`-typed property and fail. Holding the id and
     * resolving through a computed property also keeps the entitlement check
     * on every render rather than only on mount.
     */
    public int $orderId;

    /**
     * `mixed`, not `int` — `{order}` is a plain route segment (not
     * `Order $order`; see `$orderId`'s own docblock for why), so nothing
     * validates its shape before this signature does. PHP itself throws
     * `TypeError: OrderConfirmation::mount(): Argument #1 ($order) must be
     * of type int, string given` on a non-numeric segment
     * (`/checkout/confirmation/abc`) — before this method's own body runs,
     * the same "hydration happens before your code does" shape as the
     * `#[Url]` incidents in `test-for-input-crashes.md`, on route binding
     * instead of query-string hydration. Confirmed live: a full debug trace
     * (file paths, the container's dependency-resolution stack) to an
     * anonymous visitor under local's `APP_DEBUG=true`; gated to a bare 500
     * once debug is off, per the pre-deploy checklist in
     * `how-to/pentest-the-system.md`, but still an unhandled crash for a
     * customer who mistypes a URL, not the same clean 404 a bad order id
     * already gets. `is_numeric` here turns "not an order id at all" into
     * exactly that 404, same as `authorizedOrder()` already gives a
     * well-formed id nothing matches.
     */
    public function mount(mixed $order): void
    {
        if (! is_numeric($order)) {
            throw new NotFoundHttpException;
        }

        // Checked here so an unauthorised id 404s on arrival rather than
        // rendering an empty page.
        $this->orderId = $this->authorizedOrder((int) $order)->getKey();
    }

    /**
     * Drops Stripe's redirect parameters from the address bar (SEC-012).
     *
     * Stripe appends `payment_intent`, `payment_intent_client_secret` and
     * `redirect_status` to whatever `return_url` it was given. The client
     * secret is not a credential for anything this application owns, but it
     * *is* enough — with the publishable key, which is public by design — to
     * retrieve the PaymentIntent from a browser and read its amount,
     * currency and status. Confirmed by doing exactly that.
     *
     * Left alone it persists in three places: the customer's browser
     * history, the web server's access log (nginx logs the full request
     * line), and anywhere that log is shipped. The last is the real concern
     * — log aggregators routinely have wider read access than the database.
     *
     * Nothing here reads any of the three: this page resolves the order from
     * the `{order}` segment and the session claim, and deliberately reports
     * the *webhook's* payment status rather than `redirect_status`, because
     * a redirect is trivially forgeable by typing the URL. So they can be
     * discarded with no loss.
     *
     * A redirect rather than a header tweak, because only replacing the URL
     * clears the browser history entry as well. An nginx log-format change
     * would fix the log alone and leave the history and any future
     * screenshot or shared link carrying it.
     */
    public function rendering(): void
    {
        if ($this->stripeParametersPresent()) {
            $this->redirectRoute(
                'checkout.confirmation',
                ['order' => $this->orderId],
                navigate: false,
            );
        }
    }

    private function stripeParametersPresent(): bool
    {
        return request()->hasAny([
            'payment_intent',
            'payment_intent_client_secret',
            'redirect_status',
        ]);
    }

    #[Computed]
    public function order(): Order
    {
        return $this->authorizedOrder($this->orderId);
    }

    /**
     * The order, if this visitor is entitled to see it.
     *
     * @throws NotFoundHttpException
     */
    private function authorizedOrder(int $orderId): Order
    {
        $user = auth()->user();

        if ($user instanceof User) {
            /** @var Order|null $owned */
            $owned = $user->orders()->find($orderId);

            if ($owned !== null) {
                return $owned;
            }
        }

        // The guest path, and the path for a registered customer whose order
        // was placed before they signed in. Scoped to this session only.
        if (session(self::SESSION_KEY) === $orderId) {
            /** @var Order|null $justPlaced */
            $justPlaced = Order::find($orderId);

            if ($justPlaced !== null) {
                return $justPlaced;
            }
        }

        // 404 rather than 403: a 403 would confirm the order exists, which is
        // the fact an enumerating caller is after.
        throw new NotFoundHttpException;
    }

    public function render(): View
    {
        $order = $this->order();

        return view('livewire.checkout.order-confirmation', [
            'order' => $order,
            'payment' => $order->payment,
            'items' => $order->orderItems()->with('productVariation.product')->get(),
        ]);
    }
}
