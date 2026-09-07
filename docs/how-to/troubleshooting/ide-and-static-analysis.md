# Troubleshooting — IDE noise, Larastan, Pint, and scripted edits

Part of the [troubleshooting index](../troubleshooting.md). Cases where a
static check disagrees with the IDE, disagrees with itself, or where a test
stays green after the thing it claims to test was deleted.

---

## The IDE reports core Laravel and Filament types as undefined

**Symptom.** `Undefined type 'Illuminate\Database\Eloquent\Model'`,
`Undefined method 'hasMany'`, or `Undefined type
'Filament\Support\Contracts\HasLabel'` on code that runs correctly.

**Cause.** `vendor/` is installed inside the container, not on the host, so the
IDE has nothing to index. Filament compounds it — its heavy trait use produces
false "undefined method" reports even when vendor *is* indexed.

**Fix.** None needed. Confirm with the real toolchain:

```bash
docker compose exec app ./vendor/bin/phpstan analyse
```

**Why it recurs.** The diagnostics look exactly like real errors and appear on
every file touched.

**Prevention.** Larastan in the container is the authority for type errors. A
red squiggle with a green Larastan run is noise; treat the container's answer as
the only one.

---

## A class fails to autoload after an editor revert

**Symptom.** `Error: Interface "App\Enums\HasLabel" not found`, on a file that
was correct minutes earlier and shows no intentional change.

**Cause.** An editor undo or a stale buffer flush restored an earlier version of
the file. Here it reverted a `use` statement to a form with the namespace
separators stripped, so `implements HasLabel` resolved against the current
namespace instead of the imported one.

**Fix.** Restore the correct import. Check open buffers for other stale copies
before continuing.

**Why it recurs.** The file looks nearly right, git shows a small diff, and the
error names a class nobody wrote.

**Prevention.** An error naming a type in the *current* namespace that should
have been imported is an import problem, not a missing class. Confirm with a
full-directory grep rather than checking the file the error points at:

```bash
grep -rn "^use " src/app/Enums/
```

---

## Shell find-and-replace mangles PHP namespaces

**Symptom.** `use FilamentSupportContractsHasLabel;` or `use App\Enums${e};`
after a `sed` or `perl` sweep. Backslashes disappear or variables fail to
expand.

**Cause.** Namespace separators are escape characters in every layer they pass
through — the shell, the regex engine, and the replacement string. `\E` in a
Perl pattern terminates a quoted section, so `App\Enums` silently stops matching
at `App`.

**Fix.** Repair the files, then use the editor tooling for anything containing a
namespace. Reserve shell replacement for text with no backslashes.

**Why it recurs.** It appears to work — the command reports success and most
lines look right, so the damage is found later by the autoloader.

**Prevention.** Never pipe PHP namespaces through `sed` or `perl`. If a sweep is
unavoidable, verify immediately:

```bash
grep -rh "^use " src/app/Enums/ | sort -u
```

---

## An authorization test passes with the authorization check deleted

**Symptom.** A test asserting that a denied actor cannot perform an Action is
green. Deleting the `Gate::forUser($actor)->authorize(...)` call from that
Action leaves it green.

**Cause.** The Action composes another Action, and the *inner* one raised the
`AuthorizationException` the test attributed to the outer one. In this
codebase `CreateProduct` calls `AddProductVariation`, and ADR-0007 requires
the actor to be passed down, so both check a policy. A test actor holding
neither `create_product` nor `create_product_variation` is denied twice, and
the assertion cannot tell which check did it.

**Fix.** Give the actor every permission the composed Actions need *except*
the one under test:

```php
// Tests CreateProduct's own gate, because AddProductVariation's will pass.
$actor = catalogueActor('create_product_variation');
```

**Why it recurs.** `expect(...)->toThrow(AuthorizationException::class)` is the
natural assertion and it is correct — the exception type is right, the write
really was prevented, and the test description matches what happened. Only the
attribution is wrong, and nothing in the output distinguishes the two sources.
Every composed Action that passes an actor down can reintroduce it, and
ADR-0007 requires them all to.

**Prevention.** Delete the check under test and confirm the test goes red.
Grant the actor exactly 1 permission short of success rather than granting
none — an actor with no permissions at all is denied by whichever check runs
first, which is rarely the one being tested.

---

## A failing Pint check reaches `main` anyway

**Symptom.** `./vendor/bin/pint --test` fails in CI on `main` itself —
`ordered_imports` on a file nobody touched recently, e.g. one `use`
statement in `ProductCategoriesTable.php` out of alphabetical order. The PR
that introduced it shows the `test` check as failed, not pending, and is
already merged.

**Cause.** Two separate gaps, not one. First, `.github/workflows/ci.yml`
triggers `pull_request` only for PRs targeting `branches: [main]`. A chain
of feature branches merging into each other before finally reaching
`main` (`lookup-resources` → `feature` → `development` → `main`) only runs
CI on that last hop — `gh pr checks` on the earlier PRs in the chain
reports "no checks reported," which reads as clean but means untested, not
passing. Second, `main` has no branch protection rule
(`gh api repos/.../branches/main/protection` returns `404`), so nothing
stops a merge when the one check that did run comes back red.

**Fix.** Run `./vendor/bin/pint` (not `--test`) on the offending file,
confirm the full suite with `--test`, and land the fix as its own small PR
rather than amending history on `main`.

**Why it recurs.** Every intermediate branch in a merge chain looks safe
because nothing failed on it — nothing ran. And a red final check does not
by itself stop the merge button; it only stops it if branch protection is
configured to require that check.

**Prevention.** The first half is fixed, the second cannot be.

1. **Fixed.** `ci.yml` now triggers on every pull request rather than only
   those targeting `main`, so an intermediate PR in a chain is checked
   rather than reporting "no checks reported".
2. **Not available on this repository.** Requiring a green check before
   merging needs branch protection or a ruleset, and GitHub offers neither
   for a private repository on a free organisation plan — the API answers
   `Upgrade to GitHub Pro or make this repository public`. Both developers
   also hold `write` rather than `admin`, so it is not ours to set even if
   the plan allowed it.

   Until the organisation upgrades or the repository goes public, a red
   check is advisory. Run the gate locally before pushing:

   ```bash
   docker compose exec app ./vendor/bin/pint --test
   docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G
   docker compose exec app ./vendor/bin/pest
   ```

---

## A scripted rewrite reports success and leaves broken PHP

**Symptom.** A `perl -pi`, `sed -i`, or Python rewrite over a PHP file exits
zero and prints nothing. The file is syntactically broken — a closure signature
truncated mid-parameter, a namespace merged into the line above it, or CRLF
introduced into a file the rest of the repository keeps as LF. Nothing reports
it until a test run minutes later, by which point the failure looks like a bug
in the code the edit was aimed at.

Observed four times across the catalogue and cart slices. The last was a
two-substitution `perl -0pi -e` replacing a `DB::transaction(function () use
(...)` opening: the first substitution matched, the second matched a line it
was not meant to, and the result was
`syntax error, unexpected token ","` on the closure's parameter list.

**Cause.** A stream editor has no model of PHP. A pattern written against the
file as remembered rather than as it currently reads will match a different
span, match twice, or match nothing — and all three exit zero. Multi-line
patterns with `-0` are the worst case, because the span they can silently
swallow is unbounded.

**Fix.** `php -l` on the file, immediately — not at the next test run:

```bash
docker compose exec app php -l app/Actions/Cart/MergeGuestCart.php
```

Restore from a copy taken before the edit. `cp` before, `php -l` after.

**Why it recurs.** Every other tool in this workflow reports its own failures.
A stream editor's success and its no-op are the same exit code, and its
mangling is also that exit code.

**Prevention.** Use the editor tooling for edits to PHP, which fails loudly on
a non-matching target. Where a scripted rewrite is genuinely the right tool —
a repeated mechanical mutation across several files, as in a guard-deletion
sweep — pair it with `cp` to a scratch copy beforehand and `php -l` on every
touched file immediately after, in the same command, so the check cannot be
skipped.

---

## A guard-deletion test stays green because a second guard subsumes it

**Symptom.** A validation guard is deleted to prove its test is real, and the
test stays green. Unlike the authorization case, no second Action is involved
and no composition is hiding the source — the Action has one call site and the
assertion names the right exception class.

**Cause.** Two guards in the same Action raise the *same* exception class, and
a database constraint makes the second one's range cover the first's. In
`AddToCart` and `UpdateCartItemQuantity` the `$quantity < 1` guard and the
`min_order_quantity` guard both raise `InvalidCartQuantityException`, and
`chk_products_min_order_quantity_positive` keeps every minimum at 1 or more —
so on an empty cart line every quantity the first guard rejects, the second
rejects too. `expect(...)->toThrow(InvalidCartQuantityException::class)` cannot
tell them apart.

**Fix.** Two changes, both needed:

1. Assert the message, not only the class — Pest's `toThrow()` takes a second
   argument matched against the message, and the two guards have different
   wording because they are different messages to a customer.
2. Add the case only the guard under test can catch. For `AddToCart` that is a
   negative quantity against an existing line: `-2` added to a line of `5` sums
   to `3`, which clears both the minimum and the stock check, so without the
   guard "add to cart" silently removes two units.

**Why it recurs.** Two guards raising one exception class is good design — the
caller handles one failure mode — and the subsumption is a property of a
`CHECK` constraint in a migration, not of either guard. Nothing at the call
site or in the test hints that one range contains the other.

**Prevention.** When 1 Action raises the same exception class from more than
one place, the tests for those places assert the message. A shared exception
class with distinct static factories (`notPositive()`,
`belowMinimumOrder()`) is the signal to check for.

---

## PHPStan crashes at the memory limit and reports "Found 1 error"

**Symptom.** `./vendor/bin/phpstan analyse` ends with `Found 1 error` and
`Result is incomplete because of severe errors`. The single error is
`Child process error: PHPStan process crashed because it reached configured
PHP memory limit: 128M ... while running parallel worker`.

**Cause.** The container's CLI `memory_limit` is 128M and PHPStan's parallel
workers exceed it as the analysed codebase grows. It is a harness failure
reported in the same shape as an analysis finding.

**Fix.**

```bash
docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G
```

**Why it recurs.** `README.md` and `CLAUDE.md` both give the bare
`phpstan analyse` form, which worked until the codebase crossed the threshold
and will keep appearing to work on smaller checkouts.

**Prevention.** Pass `--memory-limit=1G` always. Setting `memory_limit` in
`phpstan.neon` would remove the flag from every call site and is the better
fix if this recurs on CI.

---

## Larastan reports an enum comparison as always false, on a property that really is that enum

**Symptom.** `phpstan analyse` fails on a `===` comparison against a
backed-enum-cast property — `Strict comparison ... will always evaluate to
false` — even though `php artisan tinker` confirms the property really does
cast to that enum at runtime, and the comparison is correct.

**Cause.** Larastan's model-property inference (`checkModelProperties: true`)
reads the column's raw database type for a MySQL `enum(...)` column and
infers a union of string literals (`'percentage'|'fixed'`) for the property,
rather than reading the `casts()` method. Every model in this codebase types
`casts()`'s return as `@return array<string, string>`, which is accurate for
scalar casts but tells Larastan nothing about which enum class a given key
casts to — so for a property backed by a DB-level `enum` column *and* cast to
a PHP backed enum, Larastan's two inference paths disagree, and the DB-driven
one wins. `Coupon::$type`/`$scope` were the first case: no earlier merged
Action compared a `Coupon`, `Order`, or `Payment` enum-cast property with
`===` against an enum case, so the mismatch was latent since the schema
migrations, not introduced by the code that first tripped it.

**Fix.** Add explicit `@property` docblock annotations naming the enum class,
above the model's class declaration:

```php
/**
 * @property CouponType $type
 * @property CouponScope $scope
 */
class Coupon extends Model
```

This is the standard Laravel/Larastan convention for typing magic properties,
and it takes priority over both the DB-column inference and `casts()`'s
generic return type.

**Why it recurs.** Any model with a MySQL `enum(...)` column cast to a PHP
backed enum is affected the moment code compares that property with `===`
against an enum case — `Order::$status`/`$payment_status`/`$payment_method`
are the next likely case, once `TransitionOrderStatus` is built and compares
against `OrderStatus`.

**Prevention.** When adding the first `===` comparison against a new
enum-cast property, run Larastan on the touched files before assuming the
comparison is fine — the runtime cast being correct (confirmed via tinker)
does not mean Larastan agrees. Add the `@property` annotation to the model at
the same time, rather than reaching for `treatPhpDocTypesAsCertain: false` in
`phpstan.neon`, which would silence this class of check project-wide instead
of fixing the one model's missing type information.
