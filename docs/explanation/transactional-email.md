# Transactional email

How this application sends email, and why in the shape it does. First
introduced by ADR-0019's order-confirmation requirement (Consumer Rights
Directive Art. 8(7)); this page is the pattern every later mailable
follows.

## What "transactional" means here

Email the shop *must* send as part of an action the customer took: the
order confirmation, the newsletter opt-in confirmation, the "you are
unsubscribed" acknowledgement. Not marketing — a newsletter *campaign* is a
separate concern with its own consent rules (`regulatory-compliance.md`,
ePrivacy Art. 13), and this project does not send campaigns.

## The mechanism

- **Mailables, not Notifications.** `App\Mail\*`, each a `Mailable`. The
  `Notification` system's multi-channel routing (`toMail`, `toDatabase`,
  `toBroadcast`) buys nothing here — every one of these is email and only
  email — and a `Mailable` keeps the template and the data in one class
  that is trivial to render in a test (`(new OrderPlaced($order))->render()`).

- **Every mailable is `ShouldQueue`.** A slow or unreachable mail host must
  never hold a web request open — checkout in particular. `QUEUE_CONNECTION`
  is `database` (`.env`), so a `jobs` table row is written and a worker
  sends it. In tests `QUEUE_CONNECTION=sync` and `MAIL_MAILER=array`, so
  `Mail::fake()` / `Mail::assertQueued()` see it synchronously.

- **`SerializesModels`, and the view re-reads the model.** The job payload
  carries the order *id*, not a frozen copy. If erasure somehow runs
  between the queue write and the send, the mailable renders the
  anonymised order rather than leaking a pre-erasure snapshot. It also
  keeps the payload small.

- **Sent after the transaction commits, never inside it.**
  `CheckoutPage::placeOrder` queues `OrderPlaced` *after*
  `DB::transaction(...)` returns — a rolled-back order must not leave a
  confirmation job behind. Same rule anywhere else a mailable follows a
  write.

- **Markdown templates**, `resources/views/mail/`. Laravel's default mail
  theme (responsive, tested across clients). `resources/views/vendor/mail/`
  is not published — if the shop ever needs branded email, that is where a
  theme override goes, and it is a deliberate change, not drift.

## Environments

| | `MAIL_MAILER` | Where mail goes |
|---|---|---|
| Local (Docker) | `smtp` → `mailpit:1025` | Mailpit UI at `:8025` — nothing leaves the machine |
| Test | `array` | Held in memory; `Mail::fake()` asserts against it |
| CI | `array` (via `phpunit.xml`) | Never actually sent |
| Production (Forge) | a real transactional provider | **Not configured** — a go-live task. `MAIL_FROM_ADDRESS` and a provider (Postmark / SES / Resend) must be set, and SPF/DKIM/DMARC on the sending domain. `how-to/deploy-and-host.md` is where that checklist item lives |

## What each mailable must and must not contain

- **Must**: everything the regulation the mailable exists for requires. For
  `OrderPlaced` that is CRD Art. 6's pre-contractual information as
  concluded (line items with variation, image, unit and line price,
  quantity; totals with VAT; the delivery/billing address; the payment
  method label; the order number and tracking link; the 14-day withdrawal
  information and a link to the model form).
- **Must not**: payment-card numbers, Stripe tokens (`pi_*`,
  `client_secret`), passwords, session identifiers, or another customer's
  data. `OrderPlacedTest` asserts the negative cases directly.

## Current mailables

| Mailable | Trigger | Regulation |
|---|---|---|
| `App\Mail\OrderPlaced` | `CheckoutPage::placeOrder`, after commit, both payment paths | CRD Art. 8(7); GDPR Art. 6(1)(b) |
| `App\Mail\NewsletterConfirmation` | `SubscribeToNewsletter`, when a `Pending` row is created | ePrivacy Art. 13 |
| `App\Mail\NewsletterUnsubscribed` | the unsubscribe route, after the status flips | GDPR Art. 7(3) acknowledgement |

## Not done

- Production provider + domain auth (go-live).
- A "your payment failed / order cancelled" mailable — an order abandoned
  at the card step is cancelled by `carts:expire` / the payment-failure
  path; telling the customer is a reasonable follow-up, not a legal
  obligation.
- Localisation. Templates are English only; `laravel-lang/common` is
  installed but the mail strings are not extracted.
