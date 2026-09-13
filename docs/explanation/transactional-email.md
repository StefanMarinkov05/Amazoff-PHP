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

- **A card order's confirmation waits for the payment (ADR-0022).** The
  contract a CRD Art. 8(7) confirmation confirms is concluded at placement
  for cash on delivery, but at *payment* for a card sale. Sending at
  placement for both — which this page previously described as correct —
  meant a customer who reached Stripe Elements and closed the tab was told
  they had bought something. The card path now sends from
  `App\Listeners\SendOrderPlacedConfirmation`, on `OrderStatusChanged`
  reaching `Paid`. ADR-0011's rule picks the listener over a call inside
  the webhook Action: a queued email cannot be rolled back, so it belongs
  on the after-commit event rather than in the transaction.

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

## Mail to staff

`ContactMessageReceived` is the one mailable addressed to the shop rather
than a customer. It goes to a single inbox,
`config('mail.contact_notification_address')` (`.env`
`MAIL_CONTACT_NOTIFICATION_ADDRESS`, falling back to `MAIL_FROM_ADDRESS`),
not to each staff account: a shared inbox is where a shop answers mail, and
a per-role recipient query would have to special-case the administrator,
who holds no permission rows (`Gate::before`, ADR-0006).

- **Reply-To is the sender**, so answering the email answers the customer.
- **The button opens `ViewContactMessage`**, whose **Mark handled** header
  action sets `handled_at` in one click. The link grants nothing by itself —
  the panel still requires a login and `update_contact_message`.
- **The sender's text is untrusted and is rendered inside a code fence.**
  Markdown templates escape HTML but still parse Markdown, so a message
  containing `[Reset your password](https://…)` would otherwise arrive in
  the staff inbox as a working link, sent from the shop's own domain. The
  fence is one backtick longer than the longest backtick run in the text, so
  the sender cannot close it. `ContactMessageReceivedTest` covers a plain
  link and both ways of closing the fence.

## Current mailables

| Mailable | Trigger | Regulation |
|---|---|---|
| `App\Mail\OrderPlaced` (COD) | `CheckoutPage::placeOrder`, after commit — cash on delivery only | CRD Art. 8(7); GDPR Art. 6(1)(b) |
| `App\Mail\OrderPlaced` (card) | `SendOrderPlacedConfirmation`, listening on `OrderStatusChanged` when the order reaches `Paid` (ADR-0022) | CRD Art. 8(7); GDPR Art. 6(1)(b) |
| `App\Mail\NewsletterConfirmation` | `SubscribeToNewsletter`, when a `Pending` row is created | ePrivacy Art. 13 |
| `App\Mail\NewsletterUnsubscribed` | the unsubscribe route, after the status flips | GDPR Art. 7(3) acknowledgement |
| `App\Mail\ContactMessageReceived` | `ContactForm::submit`, after the row is written — to the shop inbox, not the sender | none; operational |

## Not done

- Production provider + domain auth (go-live).
- A "your payment failed / order cancelled" mailable. An order abandoned at
  the card step is cancelled by `orders:expire-unpaid` (ADR-0022), which
  releases its stock and says nothing to the customer. Telling them is a
  reasonable follow-up, not a legal obligation, and was deliberately
  deferred rather than forgotten — ADR-0022's rejected alternatives.
  (This entry previously credited `carts:expire` with the cancellation; it
  never did that, and nothing did until ADR-0022.)
- Localisation. Templates are English only; `laravel-lang/common` is
  installed but the mail strings are not extracted.
