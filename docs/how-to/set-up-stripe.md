# How to set up Stripe locally

From nothing to a working card payment on your machine, then how to extend
the integration. What the pieces *are* is `explanation/stripe-payments.md`;
this is the recipe.

Nothing here touches live mode. Every key below is a test key, every card is
a test card, and no real money moves.

## 1. Get a Stripe account and test keys

Sign up at <https://dashboard.stripe.com/register>. Test mode needs no
business details, no bank account, and no verification.

Open <https://dashboard.stripe.com/test/apikeys>. **Check the toggle at the
top says Test mode** before copying anything.

| Dashboard label | `.env` key | Looks like |
|---|---|---|
| Publishable key | `STRIPE_KEY` | `pk_test_…` |
| Secret key | `STRIPE_SECRET` | `sk_test_…` |

A `pk_live_`/`sk_live_` pair in `.env` is a mistake, not a shortcut.
Implementation standard #17 keeps real keys out of `.env.example` too — the
committed file has empty values and comments only.

## 2. Install the Stripe CLI

The CLI is what gives you a webhook signing secret locally without exposing
your machine to the internet. On Debian/Ubuntu:

```bash
curl -s https://packages.stripe.dev/api/security/keypair/stripe-cli-gpg/public \
  | gpg --dearmor | sudo tee /usr/share/keyrings/stripe.gpg > /dev/null
echo "deb [signed-by=/usr/share/keyrings/stripe.gpg] https://packages.stripe.dev/stripe-cli-debian-local stable main" \
  | sudo tee /etc/apt/sources.list.d/stripe.list
sudo apt update && sudo apt install stripe
```

macOS: `brew install stripe/stripe-cli/stripe`. Anything else, or if the apt
repo misbehaves: a static binary from
<https://github.com/stripe/stripe-cli/releases/latest>.

Then:

```bash
stripe login
```

Opens a browser, you approve, credentials are stored in your OS keychain.

## 3. Get the webhook signing secret

This is **not** the same value as `STRIPE_SECRET`, and for local development
it does not come from the dashboard — the dashboard's webhook secrets are
for endpoints with a public URL.

```bash
stripe listen --forward-to localhost:8080/stripe/webhook
```

It prints:

```
Ready! Your webhook signing secret is whsec_… (^C to quit)
```

Put that in `STRIPE_WEBHOOK_SECRET`. **Leave the command running** — it is a
live tunnel, not a one-off setup step. Stripe pushes events to the CLI, the
CLI forwards them to your container, and it signs them with that secret.

Your `.env` block should now read:

```dotenv
STRIPE_KEY=pk_test_…
STRIPE_SECRET=sk_test_…
STRIPE_WEBHOOK_SECRET=whsec_…
STRIPE_CURRENCY=eur
STRIPE_WEBHOOK_TOLERANCE=300
```

`STRIPE_WEBHOOK_TOLERANCE` must never be blank. A blank value casts to `0`,
and stripe-php treats a tolerance of exactly `0` as "skip the recency check
entirely" rather than "reject everything" — which would make a captured
request replayable forever. Both `config/services.php` and the middleware
floor it at 60 for that reason, but leaving it blank is still a mistake
worth not making.

Restart the app container after editing `.env`, or config stays cached:

```bash
docker compose exec app php artisan config:clear
```

## 4. Make a payment

With `stripe listen` running in one terminal:

1. Add something to the basket at <http://localhost:8080/catalogue>.
2. Go to the basket, click **Checkout**.
3. Fill the form, choose **Card** as the payment method, place the order.
4. Card `4242 4242 4242 4242`, any future expiry, any CVC, any postcode.

You should see, in order: the `stripe listen` terminal logging
`payment_intent.succeeded` and a `200` from your endpoint; the payment row
moving to `Paid` in **Payments** in the admin panel; and the event itself
listed under that payment's **Stripe events**.

If the confirmation page says "we are still confirming your payment", the
webhook has not landed — check that `stripe listen` is still running. That
message is deliberate: the redirect is not proof of payment.

### Test cards worth knowing

| Card | Behaviour |
|---|---|
| `4242 4242 4242 4242` | succeeds |
| `4000 0000 0000 0002` | declined, generic |
| `4000 0000 0000 9995` | declined, insufficient funds |
| `4000 0025 0000 3155` | requires 3D Secure authentication |

Full list: <https://docs.stripe.com/testing>.

### Firing events by hand

Faster than a real checkout when you only care about the webhook:

```bash
stripe trigger payment_intent.succeeded
stripe trigger charge.refunded
```

Note the caveat: `stripe trigger` builds its *own* PaymentIntent, unrelated
to any order in your database. `HandleStripeWebhookEvent` will log
"Stripe webhook for an unknown PaymentIntent; ignoring" and return 200 —
which is correct behaviour, not a failure. To exercise a real order end to
end, use the checkout flow.

## 5. Optional: the Stripe MCP server

Gives an agent read access to your Stripe account and to Stripe's docs
search. Useful for verifying request shapes against the real API; not
required to run or develop the integration.

```bash
claude plugin install stripe@claude-plugins-official
claude mcp add --transport http stripe https://mcp.stripe.com
```

Then restart the session. Confirm `stripe_implementation_planner` and
`search_stripe_documentation` are available.

**If `claude: command not found`** — the VS Code extension bundles its own
binary and does not put it on your PATH. Symlink it:

```bash
mkdir -p ~/.local/bin
ln -sf ~/.vscode/extensions/anthropic.claude-code-*/resources/native-binary/claude ~/.local/bin/claude
echo 'export PATH="$HOME/.local/bin:$PATH"' >> ~/.bashrc && source ~/.bashrc
```

The glob breaks when the extension updates; re-run it with the new version
directory if `claude` disappears.

**What the MCP can and cannot do here.** The session observed while writing
this had *read* access only — `GetPaymentIntents` and `GetCharges` worked,
`PostPaymentIntents` was not exposed. So it can verify the shape of objects
that already exist, and it cannot create a payment for you. `stripe trigger`
and the checkout flow remain the way to make things happen.

## How to extend the integration

### Handle a new Stripe event

1. Add the event type to `HandleStripeWebhookEvent::STATUS_BY_EVENT_TYPE`
   if it maps cleanly to one `PaymentStatus`. If the target depends on the
   payload — as `charge.refunded` does on the amount — resolve it in
   `statusFor()` instead.
2. Check `PaymentStatus::allowedTransitions()` actually permits the move
   from the states it can arrive in. An illegal move is recorded with a
   note and not applied, which is right for out-of-order delivery but wrong
   if you meant the transition to be legal.
3. Add the event to the endpoint's enabled events in the dashboard, and to
   the `stripe listen` invocation if you filter there.
4. Test it in `tests/Feature/Payment/StripePaymentTest.php`. Remember the
   amount guard: a `payment_intent.succeeded` fixture needs
   `amount_received` **and** `currency` matching the payment row, or it
   will be correctly refused and your test will look broken.

### Add a Stripe API call

Resolve `StripeClient` from the container, never `new StripeClient(...)` —
the binding in `AppServiceProvider` pins the API version and is the seam
tests swap. In a test:

```php
$intents = Mockery::mock();
$intents->shouldReceive('create')->andReturn((object) ['id' => 'pi_x']);

$client = Mockery::mock(StripeClient::class);
// getService(), not __get(): StripeClient::__get() delegates to it, so
// mocking __get alone leaves the real delegation running.
$client->shouldReceive('getService')->with('paymentIntents')->andReturn($intents);

app()->instance(StripeClient::class, $client);
```

Any call that moves money needs an `idempotency_key` derived from something
stable — the payment id, plus the running total where a legitimate second
identical call must still go through. See `RefundPayment` for the latter.

### Close one of the known gaps

`explanation/stripe-payments.md` lists them with reasoning. The two cheapest
are the SDK timeout (a `Stripe::setHttpClient()` with a shorter
`CurlClient::setTimeout()`) and multi-secret support for signing-secret
rolls (accept an array in `VerifyStripeWebhookSignature`, try each). Both
want a test that fails first.

## Going live

Not in scope for the internship project, but the checklist that applies:
register a live webhook endpoint separately and confirm it behaves
identically to test; rotate API keys and confirm none are in the repo;
HTTPS with TLS 1.2+; review error handling across Stripe's error types; and
re-read `explanation/stripe-payments.md`'s known gaps, several of which are
"fine in test, not fine in production".
