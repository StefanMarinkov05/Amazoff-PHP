# Troubleshooting — auth, sessions, and route-level test gaps

Part of the [troubleshooting index](../troubleshooting.md). Errors where a
security guarantee looks satisfied — the code reads right, the framework
method is called — but nothing re-checks it on the request that matters, plus
the one test-infrastructure gap this exposed.

---

## `Auth::logoutOtherDevices()` is called, and other sessions stay signed in

**Symptom.** `ChangePassword` requires `current_password`, changes the
password, and calls `Auth::logoutOtherDevices()`. The password does change.
Every other browser signed in as that user keeps working — the whole reason
`current_password` is required is that a password change is the standard
response to "someone else may be signed in as me", and that half silently
did not happen. Nothing errors, Larastan is green, Pint is green, and the
code reads exactly as intended.

**Cause.** `Auth::logoutOtherDevices()` only does anything when
`Illuminate\Session\Middleware\AuthenticateSession` is in the request's
middleware stack. That middleware is what stores a password hash on the
session and compares it on each subsequent request; without it, the call
rewrites the user's hash and nothing ever compares another session against
it. It is **not** in Laravel's default `web` group — it has to be added.

What made this hard to see here: Filament *does* register it, in
`AdminPanelProvider`'s own `->middleware()` list, which the Filament
installer scaffolds. So the panel had the protection and the storefront did
not, and a grep for `AuthenticateSession` finds a hit — in a file that has
nothing to do with the storefront. The natural conclusion from that hit is
"it is registered", which is true and irrelevant.

**Fix.** Append it to the `web` group in `bootstrap/app.php`, after
`StartSession`:

```php
$middleware->web(append: [
    AuthenticateSession::class,
    EnsureAccountIsActive::class,
]);
```

`AuthenticateSession` also logs the *acting* session out unless the password
change re-issues it — `ChangePassword` already calls `session()->regenerate()`
immediately after, which is what keeps the user signed in on the browser they
just used.

**Why it recurs.** It is a security guarantee whose absence looks identical
to its presence from every angle except an actual second session: no error,
no failing test unless one is written for it, and a docblock nearby
asserting the guarantee holds. This project's own `ChangePassword` carried
the sentence "Every other session for this user is invalidated" while it was
false. A comment stating intent is not evidence of behaviour, and a
framework call being present is not evidence its precondition is met.

The same shape applies one layer up: `Login` checks `is_active` as part of
the credentials, which is a check at one instant, and nothing re-checked it
afterwards — a customer deactivated or soft-deleted mid-session kept
browsing until the session expired on its own. `canAccessPanel()` already
stated that reasoning for the panel ("a session outlives the row it
authenticated against"); the storefront had no equivalent until
`EnsureAccountIsActive`.

**Prevention.** For any auth rule, ask what re-checks it on the *next*
request, not what checked it at sign-in. When a framework method is called
for its side effect, check its precondition is registered in the stack that
actually serves the route — `php artisan tinker` printing
`app('router')->getMiddlewareGroups()['web']` answers this directly, and is
what confirmed both halves here. `AuthSessionInvalidationTest` pins the
group's contents for this reason; `docs/reference/testing/ui-tests.md` records what
each of its cases proves.

---

## A Feature test passes locally and fails in CI with `ViteManifestNotFoundException`

**Symptom.** A test that does a plain `->get('/some-route')` against a real
page — not `Livewire::test(Component::class)` — passes every time locally,
including in a container, and fails in CI with `Illuminate\Foundation\
ViteManifestNotFoundException: Vite manifest not found at:
.../public/build/manifest.json`.

**Cause.** `components/layouts/app.blade.php` calls `@vite(...)`. Vite's
`__invoke()` skips the manifest entirely when `public/hot` exists — the
marker the `vite` Docker service leaves behind while its dev server is
running — and reads `public/build/manifest.json` otherwise. Local
development always has the `vite` container running, so `public/hot` is
always there and the manifest path is never exercised. `use-ci.md`'s `test`
job deliberately has no npm/build step, verified at the time by grep that
nothing under `tests/Unit` or `tests/Feature` called `@vite`. A test that
renders a real route through the full layout — rather than mounting a
Livewire component directly, which never touches the layout — breaks that
premise, and CI has neither `public/hot` nor a built manifest, so it hits
the one code path nothing local ever exercises.

**Why a route-level test was needed at all.** `EnsureAccountIsActive` and
`AuthenticateSession` are HTTP middleware. `Livewire::test()` mounts a
component directly and never runs the request through the middleware
stack, so a component-level test proves nothing about either — the test
has to go through a real route.

**Fix.** Fake a minimal manifest for the file(s) that need it, scoped with
`beforeEach`/cleanup rather than committed to the repo or added to CI:

```php
beforeEach(function (): void {
    $buildPath = public_path('build');

    if (! File::exists($buildPath.'/manifest.json')) {
        File::ensureDirectoryExists($buildPath);

        File::put($buildPath.'/manifest.json', json_encode([
            'resources/css/app.css' => ['file' => 'assets/app.css', 'src' => 'resources/css/app.css'],
            'resources/js/app.js' => ['file' => 'assets/app.js', 'src' => 'resources/js/app.js'],
        ]));

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($buildPath));
    }
});
```

Both `file` and `src` are dereferenced unconditionally by `Vite::__invoke()`
for every entrypoint passed to `@vite()`; everything else (`css`, imports)
is read with `?? []` and can be omitted since nothing in these tests
asserts on the rendered HTML — only on status codes and redirect targets.
`Vite::fonts()` needs no equivalent fake: a missing `fonts-manifest.json`
makes it return an empty string rather than throw.

**Why it recurs.** The container that makes local development convenient
(`vite`, always running) is exactly the thing that hides this from local
runs. `pint --test` and `phpstan analyse` are also blind to it — this is a
runtime-only failure, the shape every entry in this troubleshooting tree is
about. `use-ci.md`'s own build-step decision states its verification method
(a grep for `@vite`/`Vite::`/`mix()`), which is the actual thing to re-run
before trusting that decision still holds — a route-level test added later
is exactly the kind of change that grep wouldn't be re-run for unless
someone remembered to.

**Prevention.** Before adding a test that hits a real route rather than
mounting a Livewire component, ask whether it needs to (only middleware or
full-request behaviour needs it) and, if so, either fake the manifest as
above or re-run `use-ci.md`'s grep and reconsider the no-build-step
decision if `@vite`-rendering tests are now common enough that per-file
fakes are duplicated across the suite. Run the suite with `public/hot`
temporarily renamed to reproduce the CI condition locally before trusting a
route-level test is green for the right reason.

## `pest --parallel` intermittently fails with `mkdir(): File exists` at `TestCase.php`

**Symptom.** A small, non-reproducible number of tests fail under
`--parallel --processes=4` with `ErrorException: mkdir(): File exists` at
`vendor/laravel/framework/.../Filesystem.php`, three frames under
`tests/TestCase.php:59`. Re-running the exact same failing test file alone,
or the whole suite non-parallel, passes every time. Different tests fail on
different runs — not the same file twice.

**Cause.** The fake-Vite-manifest fixture from the entry above was promoted
from a per-file `beforeEach` into `TestCase::setUp()`, so every test case
now runs `File::exists($buildPath.'/manifest.json')` then, if missing,
`File::ensureDirectoryExists($buildPath)` against the same shared
`public_path('build')` path. `ensureDirectoryExists()` is a
check-then-`mkdir()`, not an atomic create — under 4 parallel worker
processes, two workers can both pass the `File::exists()` check on the same
tick, both call `mkdir()` on the same non-recursive path, and the loser gets
`mkdir(): File exists` instead of Laravel silently treating "already there"
as success. It is a real TOCTOU race in shared test infrastructure, not a
per-test bug — the manifest path itself is process-wide, not per-worker.

**Fix.** None applied yet — noted here rather than patched blind. The
correct fix is making the directory-creation step in `TestCase::setUp()`
tolerant of a concurrent creator (e.g. suppress `mkdir()`'s own warning and
re-check `is_dir()` after, the same pattern `File::makeDirectory($path,
0755, true, true)`'s `$force` flag already uses elsewhere in the framework)
rather than `ensureDirectoryExists()`'s plain check-then-act.

**Why it recurs.** `--parallel` is the CI-shard-relevant run mode
(`docs/how-to/use-ci.md`), so this can surface in CI on an unrelated PR with
no code change of its own — the failure is a scheduling accident between
workers, not a regression in whatever the diff touches. A single-process
`pest` run, or re-running just the flagged file, will never reproduce it,
which makes it easy to mistake for "must have been a fluke" and re-run past
rather than record.

**Prevention.** Before treating a `--parallel` failure as a real regression,
re-run the specific failing file(s) alone. If they pass in isolation and the
failure is `mkdir(): File exists` at `TestCase.php`'s Vite-manifest block,
it is this race, not the change under review.
