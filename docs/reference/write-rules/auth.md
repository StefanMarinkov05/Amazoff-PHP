# Auth — expected behaviour

What happens for registration, login, an authenticated session, and a
password change — alone, and when another session or an administrator acts
on the same account concurrently. Facts as of 2026-08-30, measured against
the running stack rather than read off the code. New scenarios get added
here as they come up, in the same shape as the ones below, rather than in a
new file — this page is the one place auth's contested and near-contested
state is described, so a change here does not have to be cross-checked
against a second copy of the same text.

Why `reference/write-rules/` pages exist and what they own vs. don't is
stated in `cart.md`'s header; the same applies here. This page **does not**
cover authorization (roles, permissions, policies) — that is
`explanation/security-model.md` and `reference/permissions.md`. It covers
only identity: who is signed in, on what session, against which row.

## What enforces any of this

`App\Livewire\Auth\Login`, `Register`, and `ChangePassword` — no Action
layer, per each component's own docblock: authentication and a plain
one-table `User` write hold no invariant an Action could own (ADR-0007's
test). `Illuminate\Session\Middleware\AuthenticateSession` and
`App\Http\Middleware\EnsureAccountIsActive`, both on the storefront's `web`
middleware group (`bootstrap/app.php`) and — for `AuthenticateSession` —
also on Filament's panel stack (`AdminPanelProvider`, scaffolded by the
installer, not hand-added). `User`'s `SoftDeletes` global scope, which is
Eloquent's own guarantee rather than anything this application wrote.

Every row below states which of these actually does the work. Where none
of them do, that is stated too — "unenforced" here means exactly that,
not that the row was overlooked.

## A row here counts as verified only if…

Same rule `concurrency.md` states for contested state: verified means the
test has been observed failing with the mechanism removed and passing with
it restored. `troubleshooting.md`'s "`Auth::logoutOtherDevices()` is
called, and other sessions stay signed in" entry is what this discipline
caught — a docblock asserting a guarantee that was, at the time, false.

## One actor, no concurrency

| Action | Refused when | How | Evidence |
|---|---|---|---|
| `Register` | email already taken (ordinary case, no race) | `unique` validation rule | `AuthSessionInvalidationTest` |
| `Register` | password fails `Password::defaults()` or confirmation mismatch | validation | framework's own — not tested here, see `CLAUDE.md`'s testing philosophy |
| `Login` | wrong email or wrong password | `Auth::attempt()` returns false; **one message for both** (`auth.failed`) — naming which half failed turns the form into an account enumerator | not in this suite; ordinary Laravel auth behaviour |
| `Login` | account `is_active = false` | folded into the same `Auth::attempt()` credentials array as the password check, so it fails with the identical `auth.failed` message rather than a distinguishable one | `Login`'s own docblock; not separately tested, see "Login-boundary races" below |
| `Login` | account soft-deleted | `SoftDeletes`'s global scope excludes the row from the query `Auth::attempt()` runs, so it behaves exactly like "no such user" | `Login`'s own docblock; unenforced *by this application* — see "Known gaps" |
| `Login` | 5 failed attempts for the same email+IP within a minute | `RateLimiter`, keyed on both so one attacker cannot lock out a real customer by hammering their address | not in this suite; framework rate limiter |
| `ChangePassword` | `current_password` does not match | `current_password` validation rule, checked against the authenticated user's own row | `AuthSessionInvalidationTest` |
| `ChangePassword` | new password equals the current one | `different:current_password` rule | not directly tested; a straightforward validation rule |

## Login-boundary races — verified, not enforced by new code

Whether an admin deletes/deactivates the account, or another session
changes its password, **while a login attempt is in flight**.

`Auth::attempt()` reads the row exactly once, at the instant of the
attempt, with `is_active` folded into the same query as the password
check. There is no read-then-decide-then-act window the way there is for
stock or coupons — the decision is made on current data because there is
only one read, not a read followed later by a write against what was read.
Confirmed directly (not assumed): a deactivated account, a soft-deleted
account, and a stale (just-superseded) password were each attempted
immediately after the change, from a separate `Auth::attempt()` call
sharing no in-memory state with the one that made the change, and all
three failed cleanly; the current password on the same account succeeded.

**No test exists for this and none is planned** — it would pin
`Auth::attempt()`'s own query semantics and `SoftDeletingScope`'s own
exclusion, both framework guarantees, not this application's. `Login`'s
docblock states the same reasoning next to the code. If this class is ever
changed to read `is_active` or the user row in two separate steps (a
lookup, then a separate credential check), this reasoning stops holding and
a test should be added at that point — the risk is in a future refactor,
not in the code as it stands.

## An authenticated session, after the row changes underneath it

The row a session authenticated against can change after the session
started: an admin deactivates or deletes the account, or the user changes
their own password from another device. Unlike the login boundary above,
a session is long-lived — it does not re-read the row on every request
unless something makes it.

| Change | What ends the session | On which request | Evidence |
|---|---|---|---|
| `is_active` set to `false` | `EnsureAccountIsActive` middleware | the next request after the change | `AuthSessionInvalidationTest` |
| Account soft-deleted | `EnsureAccountIsActive` middleware (`$user->trashed()`) | the next request after the change | `AuthSessionInvalidationTest` |
| Password changed, by the same session that changed it | `session()->regenerate()`, called by `ChangePassword` immediately after the write | same request — the acting session is re-issued, not ended | `AuthSessionInvalidationTest` |
| Password changed, on *every other* session for that user | `Auth::logoutOtherDevices()` plus `AuthenticateSession` middleware comparing the session's stored hash against the current one | the other session's next request | `AuthSessionInvalidationTest` |

`EnsureAccountIsActive` invalidates the session outright (`session()->invalidate()`
plus token regeneration) rather than only calling `Auth::logout()` — logging
out alone would leave the old session's data (cart key, flash state) to be
inherited by whoever signs in next on that browser.

The panel side of the first two rows is `canAccessPanel()` — checked on
every panel request already, by construction, so no separate middleware was
needed there; that is *why* `EnsureAccountIsActive` exists, to give the
storefront the equivalent of what the panel already had.

## Two actors on the same account at once — what is genuinely contested

Unlike catalogue stock or coupon redemption counts, almost nothing in this
area is contested state in the `concurrency.md` sense — there is no
invariant two concurrent writes can jointly violate, because each of these
writes replaces a single column with a value that needs no other row to
validate against.

| Scenario | Contested? | What happens | Evidence |
|---|---|---|---|
| Two registrations, same email, truly concurrent | Yes — insert-vs-insert on `users.email` (`UNIQUE`) | Both pass the `unique` *rule* (neither has committed yet); the index refuses the loser's `INSERT` with `UniqueConstraintViolationException`, caught and converted to the same `email` form error the ordinary duplicate produces | `AuthSessionInvalidationTest` (simulated via the `creating` model event, the seam between validation and insert — not a two-process test; see "Why not `tests/Concurrency`" below) |
| Password changed from two sessions of the same account, truly concurrent | No — no invariant links the two writes | `UPDATE users SET password = ?` twice on one row; InnoDB serialises them, last commit wins. Both hashes were legitimately chosen by someone with the current password at the time they wrote, so there is no lost update to prevent | not tested; there is nothing to prove |
| Admin deletes the account while the account holder is mid-`ChangePassword` submission | No — the two writes touch the row without a joint invariant | Whichever commits last wins the row's final state. If the delete lands last, the session is caught by `EnsureAccountIsActive` on its *next* request (not mid-flight — the in-progress request that already passed middleware completes). If the password change lands last, the account is un-deleted in effect only if `delete()` and `update()` interleave in a way that leaves `deleted_at` null — verify before relying on this if it ever becomes a designed flow; today nothing depends on the ordering | not tested; no invariant to test |
| A stale in-memory `User` instance (loaded before a concurrent password change) is later `->save()`d for an unrelated field | No — Eloquent only writes dirty attributes | The unrelated field's write succeeds; the password column is untouched because it was never marked dirty on the stale instance, so the concurrent change is not clobbered back to the old value | confirmed directly in a tinker session; not automated, since this is Eloquent's own dirty-attribute tracking, not application logic |

### Why not `tests/Concurrency`

The two-process harness in `tests/Concurrency` (`RaceHelper.php`) exists to
prove a *lock* correctly serialises two connections around a shared
invariant, with a rendezvous layered on when the two sides are asymmetric
Actions (`concurrency-and-locking.md`, "A cross-Action race needs a fourth
thing"). Of the rows above, only the duplicate-email one is genuinely
contested, and what needs proving there is narrower: that the exception is
caught and converted, not that MySQL's unique index enforces uniqueness
under real concurrency — the index doing its job is not this application's
to re-prove. Simulating the interleaving with a model event reaches that
exact code path with less machinery than spawning two OS processes for it.
If a *different* auth-area invariant is added later that genuinely needs
two real connections to prove (a lock, a retry, a cross-table check), it
belongs in `tests/Concurrency` per the normal rule, and should be added to
`concurrency.md`'s table, not this page.

## Known gaps

- **Not actually a gap, but easy to mistake for one: `canAccessPanel()`
  checks `is_active` but not `trashed()` directly.** Confirmed directly:
  `User::find()` (what resolves the authenticated user on each request)
  already excludes a soft-deleted row via the `SoftDeletes` global scope,
  the same mechanism `Auth::attempt()` relies on for login — so a fresh
  resolution of a soft-deleted staff member never reaches `canAccessPanel()`
  at all. The one case that does bypass it: a **stale, already-resolved**
  `User` instance held in memory from before the delete returns `true` from
  `canAccessPanel()` if called on it directly, same as the storefront's
  stale-instance row above — but that is not how the panel guard is
  actually invoked per-request, so it is not a reachable path today. Listed
  here so a future change that caches or holds a `User` instance across
  requests re-reads this before assuming the guard alone is sufficient.
- **No test pins `RateLimiter` throttling for `Login`.** The five-attempts
  rule is stated in the component's docblock and is ordinary framework
  behaviour (`RateLimiter::hit`/`tooManyAttempts`), not something this
  application composed — but unlike the login-boundary races above, this
  one hasn't been separately reasoned through for edge cases (does a
  successful login from a different IP but the same email reset the
  counter early enough to matter, for instance). Flagged rather than
  resolved.
- **`Register` has no test for the `Password::defaults()` policy itself**
  (length, common-password checks, whatever the app's policy actually
  configures) — deliberately, since that is Laravel's own rule engine, but
  if the policy is ever customised beyond the default, the customisation
  itself becomes this application's and would earn a test at that point.
