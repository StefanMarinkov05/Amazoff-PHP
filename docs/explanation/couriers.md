# The courier layer, end to end

How Econt and Speedy actually reach the checkout page: the four layers
between a Saloon HTTP call and a radio button, why there are four rather
than two, and what still needs verifying against a real account before this
reaches a customer. ADR-0001 ("Saloon, courier clients") is the decision;
this page is the thing that decision produced.

## The shape of it

```
  CheckoutPage / CalculateDeliveryPrice          Actions\Order\CreateOrder
        │  Courier::for($carrier)                      │  CourierManager (injected)
        │  ->offices($city) / ->quote(...)              │  ->for($carrier)->quote(...)
        ▼                                                ▼
┌───────────────────────────────────────────────────────────────────────┐
│  App\Facades\Courier  ──►  App\Support\Courier\CourierManager          │
│  Resolves a driver by carriers.code, wraps it in CachedCourierGateway  │
└───────────────────────────────────────────────────────────────────────┘
        │
        ▼
┌───────────────────────────────────────────────────────────────────────┐
│  App\Support\Courier\CachedCourierGateway  (decorator)                │
│  Caches cities()/offices()/quote(); createShipment()/label()/track()  │
│  always pass through                                                  │
└───────────────────────────────────────────────────────────────────────┘
        │
        ▼
┌───────────────────────────────────────────────────────────────────────┐
│  App\Contracts\CourierGateway  (§37 #14 — one interface, two vendors)  │
│    EcontGateway            SpeedyGateway                               │
│    maps vendor JSON → App\Support\Courier DTOs, never the reverse      │
└───────────────────────────────────────────────────────────────────────┘
        │
        ▼
┌───────────────────────────────────────────────────────────────────────┐
│  App\Http\Integrations\{Econt,Speedy}  (Saloon connectors + requests) │
│  Vendor HTTP, auth, and the per-vendor "200 with an error body" trap   │
└───────────────────────────────────────────────────────────────────────┘
```

## Why a facade on top of an interface that already abstracts

`CourierGateway` and `Courier` solve different problems, and both are
wanted:

- **`CourierGateway`** (Strategy) answers "Econt or Speedy?" — it is what
  §37 #14 is graded on, and the only thing `EcontGateway`/`SpeedyGateway`
  need to satisfy.
- **`Courier`** (Facade over `CourierManager`, a Laravel `Manager`) answers
  "which one, with whose credentials, cached how?" A caller writes
  `Courier::for($carrier)->offices($city)` and never touches a connector, a
  config key, or a cache TTL.

**The facade is a read-path convenience, not a licence to hide
dependencies.** `CheckoutPage` and `CalculateDeliveryPrice` — both read-only
with respect to the courier — call the static facade. `CreateOrder` and any
future shipment-dispatch Action constructor-inject `CourierManager`, the
same way `CreateOrder` already injects `ReserveStock`. An Action with a
hidden static dependency is harder to unit-test and Larastan sees less of
it; the facade exists so the storefront's read path doesn't have to carry
that cost for a lookup with no invariant to protect.

## Why the DTOs exist at all

Vendor JSON never crosses layer 2. `App\Support\Courier` holds `CourierCity`,
`CourierOffice`, `DeliveryQuote`, `ShipmentRequest`, `ShipmentResult`, and
`CourierTrackingEvent` — readonly value objects. Everything above
`EcontGateway`/`SpeedyGateway` reads only these; nothing else in the
codebase parses Econt's or Speedy's response shape. A field that only one
vendor calls something different is resolved once, in the gateway that
produced it, rather than at every call site.

## Caching, and why three methods are excluded from it

`CachedCourierGateway` wraps any `CourierGateway` and caches `cities()`,
`offices()`, and `quote()` — the read-only lookups. This is what makes an
office picker viable against a real third-party API at all: a city's whole
office list is fetched once per `couriers.cache.offices_ttl` (a day, by
default) and then filtered client-side in `CheckoutPage::offices()` as the
customer types, rather than firing a request per keystroke.

`createShipment()`, `label()`, and `track()` always pass straight through.
Caching a write is a bug; caching a tracking poll would mean the customer's
"in transit" never becomes "delivered" until the cache expires.

**What gets cached is plain arrays, never the DTOs themselves.**
`config('cache.serializable_classes')` is `false` — this project's own
security default — which makes every `unserialize()`-based cache store
silently discard the class of any cached object on the next read. Caching a
`Collection<CourierOffice>` directly works on the first (cache-miss) call
and then breaks every subsequent one; see `docs/how-to/troubleshooting.md`,
"A cached object comes back as `__PHP_Incomplete_Class`", for the full
failure mode and why the test suite cannot catch it.

## The fallback, and where `cod_fee` gets added

`CalculateDeliveryPrice::forCart()` calls `Courier::for($carrier)->quote()`
inside a `try`/`catch`. On `CourierUnavailableException` — a real network
failure, a timeout, or a vendor's own "200 with an error body" response —
it falls back to `carriers.base_delivery_price` and marks the resulting
`DeliveryQuote::$isEstimate` true, which `CheckoutPage`'s summary reads to
show "(estimated)" instead of a figure that looks final. An outage in a
third-party pricing API is not treated as a reason to refuse an order.

`carriers.cod_fee` — the BG cash-on-delivery handling surcharge — is added
on top of the vendor quote (or its fallback) only when the order's payment
method is cash on delivery. It is this app's own surcharge, not something
either vendor's `quote()` call already knows to include, and it is added in
`CalculateDeliveryPrice` rather than inside either gateway so both vendors
apply it identically.

`CheckoutPage`'s own office picker has a second, narrower fallback for the
same reason: Econt's demo host is a shared public environment, and every
field on the checkout form re-renders the whole component, so any field's
render can be the one whose live `offices()` call happens to land during a
slow moment on that host. `CheckoutPage::$lastKnownOffices` keeps the last
successfully fetched list (as plain arrays, same reasoning as
`CachedCourierGateway`'s own caching — see above); a later render whose live
call fails falls back to it instead of blanking a list the customer is
already looking at. `updated()` clears it the moment carrier, city, postcode
or delivery type actually changes, so a stale list is never shown for the
wrong city. `resolveOffices()` is also the single place that live call
happens — `offices()` and `courierUnavailable()` both delegate to it rather
than each running their own, since Blade reads both on every render and two
independent live calls within the same render had no guarantee of agreeing
with each other.

## What the customer's browser is never trusted with

`CheckoutPage`'s office fields (`courier_office_code`, `courier_office_name`)
are set only by `selectOffice()`, from an office `offices()` itself
returned — never typed as free text. The browser can still submit any
string as the underlying property value, so `placeOrder()` re-resolves the
submitted code against that same carrier's office list one more time before
calling `CreateOrder`, exactly the same principle CLAUDE.md states for the
order total: what the customer picked is confirmed from our own source, not
accepted from theirs. Changing the selected carrier, city, or postcode
clears the picked office immediately (`CheckoutPage::updated()`) — an Econt
office code submitted while Speedy is selected is a shipment that would
otherwise fail at label time, in the warehouse, days later.

`shipping_amount` and `orders.carrier_id` are resolved inside `CreateOrder`,
from the carrier model and the delivery address it is given — never from a
price the browser could have submitted.

## What is not yet built

This layer covers carrier and office selection at checkout, and delivery
pricing. It does **not** cover the warehouse half of slice 8:
`createShipment()`, `label()`, and `track()` exist on both gateways (§37
#14 requires the shared interface to cover them), but no Action calls them
yet. `CreateShipment` (`app/Actions/Shipment/CreateShipment.php`) still
opens a shipment with every courier column nullable, exactly as it did
before this layer existed — a future `DispatchShipment` Action is what
would call `CourierManager` to actually create the vendor shipment and fill
those columns in.

## What is unverified

Both gateways' request and response shapes are built from each vendor's
*published* API documentation, not a confirmed sandbox payload:

- **Econt** has a public demo environment
  (`ECONT_API_URL=https://demo.econt.com/ee/services/`), but no request
  against it has been captured and compared to `EcontGateway`'s mapping.
- **Speedy** has no public sandbox at all. `SPEEDY_USERNAME`/
  `SPEEDY_PASSWORD` stay blank in `.env.example` until a real account is
  issued, and `SpeedyGateway`'s field names are the least-verified part of
  this layer.

Both classes' docblocks repeat this caveat at the point it matters. Confirm
against a real sandbox response — the same discipline `CarrierSeeder`
already asks for its placeholder `cod_fee`/`base_delivery_price` figures —
before any of this reaches a real customer.

## Testing

`EcontGatewayTest` and `SpeedyGatewayTest` use Saloon's `MockClient` against
the real connectors — no network call ever leaves either suite, and each
proves the vendor-JSON-to-DTO mapping including the "200 with an error
body" trap. `CachedCourierGatewayTest` counts calls to a spy gateway to
prove the cache boundary. Every other test that touches checkout or
`CreateOrder` with a carrier attached swaps the whole `Courier` facade for
`FakeCourierGateway` (`tests/Pest.php`) — checkout must never reach Econt or
Speedy over the network from a test.
