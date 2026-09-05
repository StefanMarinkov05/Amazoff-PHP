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

## Closing the verification gap — what research found, 2026-09-06

The section above was accurate but incomplete: it named the gap without
saying what would close it. Researched against each vendor's own current
documentation (not a package README or a Stack Overflow answer) because
that is the only source `EcontGateway`/`SpeedyGateway`'s own docblocks
defer to. Recorded here rather than only in a PR description, because the
gap is exactly the kind of thing a later developer re-discovers from
scratch if it isn't written down — this doc is where `EcontGateway`'s and
`SpeedyGateway`'s own docblocks already point.

### Econt — the demo host takes a real, published login, right now

`ECONT_USERNAME`/`ECONT_PASSWORD` are blank in `.env.example`, which reads
as "needs an account nobody has yet." That is not quite right for the demo
host specifically: **`http://demo.econt.com/ee/services/` accepts a
standing public test login — `iasp-dev` / `1Asp-dev`** — documented by
Econt itself and independently confirmed by at least one third-party Econt
SDK using the identical pair as its own default. This is the demo
environment's credential, not a production one; it does nothing against
`ee.econt.com` (the live host), which still needs a real merchant account.

Practically: **§37 #13 ("at least one courier works with a real test
environment") is reachable today**, without waiting on anyone. Set
`ECONT_USERNAME=iasp-dev` and `ECONT_PASSWORD=1Asp-dev` locally (never
commit real values — `explanation/secrets-and-env.md`), leave
`ECONT_API_URL` at its existing demo default, and run `EcontGateway`
against it directly — `cities()`/`offices()` are read-only and the safest
first call.

There is also a real, machine-readable **OpenAPI spec**:
`https://ee.econt.com/services/openapi.yaml`. Every endpoint path this
codebase's Econt requests use was checked against it directly and matches
exactly:

| Request class | Path | Confirmed against spec |
|---|---|---|
| `SearchCitiesRequest` | `/Nomenclatures/NomenclaturesService.getCities.json` | yes |
| `GetOfficesRequest` | `/Nomenclatures/NomenclaturesService.getOffices.json` | yes |
| `CalculateShipmentRequest` (`mode: calculate`) | `/Shipments/LabelService.createLabel.json` | yes |
| `CreateShipmentLabelRequest` (`mode: create`) | `/Shipments/LabelService.createLabel.json` | yes |
| `GetShipmentStatusesRequest` | `/Shipments/ShipmentService.getShipmentStatuses.json` | yes |

The `mode` field distinguishing a price calculation from a real label
creation, both through the same endpoint, is Econt's own design — not a
guess `EcontGateway` made up, and not something to "simplify" into two
separate calls. **The paths were right all along; only a live call was
missing.** Read the spec directly before changing any Econt request shape —
it is the same authority `EcontGateway`'s own docblock already names,
just not yet fetched and checked line by line until now.

### Speedy — the SOAP service this doc's history might suggest is gone

Two things worth stating plainly, because the search results for "Speedy
API" surface both eras at once and it is easy to land on the wrong one:

**Speedy's legacy SOAP service (WSDL at `speedy.bg/eps/main01.wsdl`) was
fully decommissioned — support ended 2024-09-30, and the service itself is
gone.** `SpeedyConnector`'s existing docblock already says "Speedy's REST
Web API," and that is the correct, current choice — nothing here needs
changing on that account. This is recorded so nobody "fixes" the connector
by porting it to SOAP after finding an old WSDL link; that migration would
run backward.

**The REST API's base URL had no default in `config/services.php` — fixed
alongside this research, not left as a finding for someone else to apply.**
`SPEEDY_API_URL` was required with no fallback, unlike Econt's demo URL.
Confirmed current base: `https://api.speedy.bg/v1`, now
`services.php`'s default.

**Not the same default form Econt uses, and that difference matters.**
`env('SPEEDY_API_URL', 'https://api.speedy.bg/v1')` — the array-default
form, matching Econt's — looked right and was wrong: this repo's `.env`
carries `SPEEDY_API_URL=` present but blank (a checked-out placeholder line
nobody filled in), so `env()` returns `''`, not `null`, and an array
default only fires when the key is *absent* entirely. Confirmed live: with
the array-default form, `config('services.speedy.api_url')` resolved to an
empty string, not the URL. Fixed to `env('SPEEDY_API_URL') ?: '...'`
instead — the exact same trap this file's own `webhook_tolerance` config
already documents for a number (`(int) '' === 0`, which is why that line
wraps its default in `max()` rather than trusting `env()`'s own default
argument). A present-but-blank env var defeating a config default is a
recurring shape in this codebase, not a one-off; check for it before
trusting any `env('X', 'default')` a fresh `.env.example` produces.

**No public sandbox exists — this part of the original gap stands.**
Getting a real Speedy test account is a request, not a signup form: email
`sandbox@speedy.bg` with a name, company name, and phone number (`api.speedy.bg/web-api.html`'s
own onboarding section). There is no credential anyone can drop in today
the way Econt's demo login allows — closing §37 #13 via Speedy specifically
still depends on someone actually sending that email and waiting for a
reply, which is why Econt is the faster path to satisfying that criterion.

Field names were checked against the same source and match what
`HasSpeedyCredentials` already sends — `userName`, `password`, with
`language` and `clientSystemId` as optional fields the current trait
doesn't send yet (both have sane defaults server-side, so not sending them
is not a bug). Endpoint paths confirmed:

| Function | Path |
|---|---|
| Create Shipment | `/shipment` |
| Calculate Price | `/calculate` |
| Find Offices | `/location/office` |
| Find Sites | `/location/site` |
| Track Shipment | `/track` |
| Print Labels | `/print` |

A JSON schema is published at `https://api.speedy.bg/v1/schema` — the
right place to check a field name against before assuming
`SpeedyGateway`'s mapping is wrong, rather than guessing from the response
shape alone.

### No MCP server exists for either vendor

Checked directly, since an MCP server would have been the fastest path to
verifying both gateways without hand-rolling requests: neither Econt nor
Speedy publishes one, and none of the generic community MCP-server
registries list one either. Not surprising for a pair of regional Bulgarian
logistics APIs with no existing SDK ecosystem beyond a couple of
community-maintained wrappers. Nothing to revisit here unless one of the
vendors ships an official server later — worth a repeat search then, not a
recurring task now.

## Testing

`EcontGatewayTest` and `SpeedyGatewayTest` use Saloon's `MockClient` against
the real connectors — no network call ever leaves either suite, and each
proves the vendor-JSON-to-DTO mapping including the "200 with an error
body" trap. `CachedCourierGatewayTest` counts calls to a spy gateway to
prove the cache boundary. Every other test that touches checkout or
`CreateOrder` with a carrier attached swaps the whole `Courier` facade for
`FakeCourierGateway` (`tests/Pest.php`) — checkout must never reach Econt or
Speedy over the network from a test.
