# Troubleshooting — Docker, permissions, networking, and the host environment

Part of the [troubleshooting index](../troubleshooting.md). Errors that
come from the container/host boundary — file ownership, networking between
containers, a stray background process, or a shell rewriting a path before
Docker ever sees it — rather than from application code.

---

## Every route 500s with `tempnam(): file created in the system's temporary directory`

**Symptom.** Every page — storefront and `/admin` alike — returns 500 with
`tempnam(): file created in the system's temporary directory`. Nothing is
written to `storage/logs/laravel.log`; the file does not even exist. The
stack trace runs through `Illuminate\View\Compilers\BladeCompiler:199` into
`Illuminate\Filesystem\Filesystem:222`. `df` shows plenty of free space and
inodes, `/tmp` is `1777`, and `ls -ld storage` from `docker compose exec`
looks fine — `drwxrwxr-x`, owner `1000`.

**Cause.** `Filesystem::replace()` calls `tempnam()` against the *target*
directory, not the system temp dir, so the message names `/tmp` while the
directory actually refused is `storage/framework/views`. `docker compose
exec` runs as **root**, which can write anywhere and makes the permissions
look correct; PHP-FPM's request workers drop to **`www-data` (uid 33)**,
per the pool config the image ships. The bind-mounted `src/` keeps
its host ownership — uid 1000, mode `drwxrwxr-x` — so `www-data` is neither
the owner nor in the owning group, and has no write bit.

This appears after anything that recreates `storage/` with host ownership:
a fresh clone, a restore from backup, or a machine migration where files
arrive owned by the host user.

**Fix.** Give the group to `www-data` and make it writable, with setgid so
newly created files inherit the group rather than reintroducing the problem:

```bash
docker compose exec app sh -c '
chgrp -R www-data storage bootstrap/cache
chmod -R g+w storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod g+s {} \;
'
```

Verify as the user that actually serves requests, not as root:

```bash
docker compose exec app su -s /bin/sh www-data -c \
  'touch /var/www/html/storage/framework/views/__probe && echo WRITABLE'
```

**Why it recurs.** Three things each hide it independently. The error names
`/tmp`, which is world-writable and therefore the first thing ruled out.
`docker compose exec` runs as root, so every manual permission check passes
while every real request fails. And Laravel cannot log the failure — the log
write needs the same `storage/` it has just been denied — so
`storage/logs/laravel.log` is absent rather than informative, which reads
like "logging is misconfigured" instead of "storage is unwritable."

**Prevention.** When a permission error is suspected inside this container,
check as `www-data` (`su -s /bin/sh www-data -c '…'`), never from the
default root shell — the root shell cannot reproduce the failure by
construction. Treat an absent `laravel.log` on a 500 as evidence about
`storage/` itself rather than about logging config.

---

## Every storefront page returns 500 with `touch(): Utime failed: Operation not permitted`

**Symptom.** The whole storefront 500s. `/`, `/catalogue`, everything. The
response body is nearly a megabyte and *looks* like a real page — it carries
the `<title>Amazoff</title>` and the full compiled CSS — so a quick `curl`
that greps for "Whoops" or "Stack trace" finds nothing and suggests the page
rendered. It did not. Only the status line says so.

`storage/logs/laravel.log` has the real message, and it is not a Laravel
error at all:

```
local.ERROR: touch(): Utime failed: Operation not permitted
(View: /var/www/html/resources/views/components/site/header.blade.php)
```

**Cause.** Blade writes compiled views to `storage/framework/views/` and
`touch()`es them to track staleness. `touch()` on a file you do not own
fails even when the directory is world-writable — POSIX allows setting an
arbitrary mtime only to the file's owner or root.

The bind mount is what creates the mismatch. Files written during an earlier
run (or by `docker compose exec`, which runs as **root**) end up owned by
`root`, while PHP-FPM serves requests as **www-data**. `ls -l` is reassuring
and wrong: the files are `-rwxrwxrwx`, so permissions look fine. Ownership
is the problem, not the mode.

**Fix.**

```bash
docker compose exec app php artisan view:clear
docker compose exec app chown -R www-data:www-data \
    storage/framework/views storage/logs bootstrap/cache
```

Compiled views are regenerated on demand, so clearing them is safe.

**Why it recurs.** Any `docker compose exec app php artisan ...` that writes
into `storage/` does so as root. The next web request, as www-data, then
cannot touch what root left behind. Running an Artisan command that warms a
cache is enough to bring it back.

**Prevention.** Prefer `docker compose exec -u www-data app php artisan ...`
for anything that writes to `storage/` or `bootstrap/cache`, so the files
land with the ownership the web request expects. If a page 500s right after
an Artisan command, check ownership before looking for a code change —
nothing in `app/` caused it.

**The wider trap.** A 500 whose body renders as a plausible page defeats
grepping for error markers. Check the status code first, then read
`storage/logs/laravel.log` for the message; the HTML body is the least
reliable source. This is the same shape as the other entries here: a failure
that presents as something other than what it is.

---

## Every container is healthy and every request 504s

**Symptom.** `docker compose ps` shows all five services `running`, `db` and
`mailpit` `(healthy)`, PHP-FPM logging `NOTICE: ready to handle connections`.
Nothing has crashed and nothing is in a restart loop. Yet:

```
$ curl -o /dev/null -w '%{http_code}' http://localhost:8080/catalogue
504
$ docker compose exec app php artisan db:show
SQLSTATE[HY000] [2002] Connection timed out
```

nginx logs the matching half:

```
upstream timed out (110: Operation timed out) while connecting to upstream,
  upstream: "fastcgi://172.18.0.5:9000"
```

**What it is not.** Not a Laravel fault, not a wrong `DB_HOST`, not a
crashed worker, not a missing `.env`. Service DNS resolves correctly —
`getent hosts db` returns the right address — so the name resolution layer
is fine. The application code is never reached at all.

**Cause.** Host firewall. `ufw` ships `DEFAULT_FORWARD_POLICY="DROP"` in
`/etc/default/ufw`, which sets the iptables `FORWARD` chain policy to DROP.
Every packet between two containers on Docker's bridge traverses `FORWARD`,
so all container-to-container traffic is silently dropped — including
outbound traffic to the internet, which is why `composer audit` also fails
with a curl timeout.

The diagnostic that isolates it in one step, from inside the app container:

```php
php -r '$s=@fsockopen("db",3306,$e,$m,3); echo $s?"OPEN":"FAIL($m)";'
```

Pair it with a probe of `127.0.0.1:9000`. **Loopback works, everything else
times out** — that asymmetry is the signature, because loopback never
crosses the bridge and so never hits `FORWARD`.

**Fix.**

```bash
sudo sed -i 's/^DEFAULT_FORWARD_POLICY="DROP"/DEFAULT_FORWARD_POLICY="ACCEPT"/' /etc/default/ufw
sudo ufw reload
sudo systemctl restart docker
```

This opens nothing to the outside world: ufw's `INPUT` rules are untouched,
so published ports stay governed exactly as before. It stops ufw dropping
traffic *between* containers on Docker's own bridge. The narrower
alternative, if `DROP` must stay global, is a bridge-scoped accept —
`sudo iptables -I DOCKER-USER -i br-<id> -o br-<id> -j ACCEPT` — which does
not survive a reboot unless persisted.

**Why it recurs.** Docker installs its own iptables rules at start; a
`ufw reload`, a ufw package upgrade, or enabling ufw for the first time
re-applies the DROP policy over them. So a stack that worked yesterday
fails today with no change to the repository — and `git log` shows nothing,
because nothing in the project changed.

### The second fault, and why the fix above may not appear to work

On the occasion this entry was written, changing the ufw policy did **not**
restore the stack, and the reason is worth recording because it wasted an
hour and produced two confidently wrong diagnoses along the way.

The firewall fix had in fact worked. Proving it took one command — two
containers on a *freshly created* network, pinging each other:

```bash
docker network create probe-net
docker run --rm --network probe-net --name probe-a -d alpine sleep 60
docker run --rm --network probe-net alpine ping -c2 probe-a   # 0% packet loss
```

A clean network passed traffic immediately. The project's own network did
not, because it predated the fix — and it could not be recreated, because
`docker compose down` failed on every container with:

```
Error response from daemon: cannot stop container: <id>: permission denied
```

The host had reached a state where the Docker daemon could not signal
container processes. `kill -9` as root *did* terminate them (the process
left the process table), but the daemon's own bookkeeping never caught up,
and **newly created containers entered the same state within minutes** —
so killing them one at a time never converged: each round cleared some
containers and broke others.

Two theories were advanced and both were wrong, which is the useful part
of this entry. **AppArmor** was blamed because it was enabled — but
"enabled" is not "implicated," and a Docker restart would normally clear an
AppArmor-mediated signal failure. **Kernel orphaning** was blamed next,
because two kernels were installed (`7.0.0-14` and `7.0.0-30`) and the
stuck containers were the oldest — but containers created minutes earlier,
on the running kernel, became stuck too, which the theory cannot explain.

**The fix is a reboot**, and it should be reached for early rather than
last. It resets the kernel, the container runtime, the daemon's state, and
the firewall rules together. After a reboot the stack came up clean on the
first `docker compose up -d`, with `DB OPEN` and HTTP 200.

**The rule this suggests.** When `docker compose down` reports
`permission denied` on containers the daemon itself created, stop issuing
container-level commands. That error means the daemon has lost authority
over its own processes, and no `docker` subcommand can repair a daemon in
that state. The one-command network probe above distinguishes "the firewall
is still blocking" from "the daemon is broken" in about ten seconds, and it
is worth running *before* forming any theory about the cause.

**Prevention.** Restart Docker after any ufw change, and reboot if a stop
or restart is refused with `permission denied`. When every service is
healthy and every request still times out, probe the network from inside a
container *before* reading application code: the loopback-works /
cross-container-fails asymmetry takes one command and rules the entire
application layer out at once. Same shape as the other entries here — the
failure presents as an application error and is not one.

---

## A background test run fails after being "stopped," and a later `docker exec` path silently resolves to Windows

**Symptom.** Two unrelated-looking failures on a Windows host running Docker
through WSL2/Docker Desktop, both from the same underlying cause and both
capable of wasting real time chasing a phantom code defect:

1. A test run backgrounded through the harness is stopped, a database reset
   is done, and the *next* run still fails — sometimes with a plain
   assertion failure in an unrelated seeder, sometimes with `SQLSTATE[40001]:
   Serialization failure: 1213 Deadlock found`, sometimes with `SQLSTATE
   [HY000]: General error: 1412 Table definition has changed, please retry
   transaction` — and the specific error changes between re-runs of the exact
   same command.
2. `docker compose exec app cat /tmp/some-file.txt` (or any command
   referencing an absolute Unix path as an argument, not a heredoc) fails with
   `cat: 'C:/Users/.../AppData/Local/Temp/some-file.txt': No such file or
   directory` — a path that was never on the host at all.

**Cause.** Two separate mechanisms, easy to mistake for one bug:

For (1): stopping a background task by its harness-assigned ID kills the
*shell wrapper* the command was launched under, not necessarily every child
process it spawned. `sh -c "./vendor/bin/pest --coverage > out.txt; tail out.txt"`
spawns `pest` as a child of the `sh -c` process; killing the wrapper does not
guarantee the child dies with it. The orphaned `pest` process keeps running
inside the container — invisible to the harness, which believes it stopped
the task — and races every subsequent command against the same MySQL
container. The failure signatures above (`1213`, `1412`, an assertion that
should already be true) are exactly what two independent transactions
fighting over the same tables and a mid-flight `migrate:fresh` look like,
and they change between runs because the race is non-deterministic. `ps`
inside the container's PID namespace does not show it either if queried at
the wrong moment relative to `docker compose top`, which reports host-side
PIDs — `docker compose top app` is the reliable check; `ps aux` inside the
container frequently is not installed at all on this image.

For (2): Git Bash (MSYS2) rewrites any argument that looks like a POSIX
absolute path — `/tmp/...`, `/var/...` — into its Windows equivalent *before*
handing it to the program being run, including arguments meant for a command
running inside a Linux container that has never heard of `C:\`. `docker
compose exec app cat /tmp/coverage-run.txt` becomes, by the time Docker sees
it, a request for a file at a Windows path that does not exist inside the
container's filesystem at all — the container itself is unaffected and the
file is exactly where it should be.

**Fix.** For (1): after stopping a background task, verify the container is
actually idle before trusting the next result — `docker compose top app`
should show only the long-lived `php-fpm`/`boost:mcp` processes, nothing
matching the command just "stopped." If a stray process is still listed,
`docker compose exec app php -r 'posix_kill(<pid>, 9);'` (`kill` is not on
`$PATH` on this image); re-check `docker compose top` afterward, since the
PID `docker compose top` reports is the host-side one and may not be visible
or killable from inside the container's own PID namespace via a plain `kill`
call — `posix_kill` from PHP running as root in an `exec` does reach it. Once
confirmed idle, reset (`migrate:fresh --seed`) before trusting any test run
that follows.

For (2): prefix the command with `MSYS_NO_PATHCONV=1` —
`MSYS_NO_PATHCONV=1 docker compose exec -T app cat /tmp/coverage-run.txt`
disables the rewrite for that invocation. Heredocs and `sh -c "..."` strings
passed as a single quoted argument are not affected, since MSYS only rewrites
argv entries that look like standalone paths — the bug is specific to a bare
path as its own argument.

**Why it recurs.** Both are invisible from the output alone. (1) produces
error messages that look exactly like the kind of environment/schema bug
this troubleshooting tree's other entries describe, so the instinct is to
debug the code under test rather than check for a second live process —
burning a full clean-reset-and-rerun cycle (minutes, on this suite) before
the real cause is even suspected. (2) fails with a Linux-shaped error message
(`cat: ... No such file or directory`) that gives no hint the path was ever
rewritten, so it reads as "the file doesn't exist" rather than "the path was
translated" — the Windows path in the error is the only tell, and it is easy
to skim past.

**Prevention.** Treat "stopped" as a claim to verify, not a fact, for any
background task that runs inside a container — `docker compose top app`
before trusting the next result against that container, every time,
not just after an unusual-looking failure. For any `docker compose exec`
argument that is a bare absolute path rather than a quoted string or
heredoc, reach for `MSYS_NO_PATHCONV=1` by default on this host rather than
after the first confusing "No such file" error.

---

## A cached object comes back as `__PHP_Incomplete_Class`, only on the second request

**Symptom.** The first checkout request for a given city/carrier works —
`CachedCourierGateway::offices()` fetches from Econt or Speedy, maps it, and
returns a real `Collection` of `CourierOffice`. The *next* request for the
same city, inside the cache TTL, 500s with `TypeError: ...offices(): Return
value must be of type Illuminate\Support\Collection, __PHP_Incomplete_Class
returned`. The row is genuinely in the `cache` table, and manually reading it
with a bare `unserialize($row->value)` in Tinker reconstructs the object
perfectly — the stored bytes are not corrupted.

**Cause.** `config('cache.serializable_classes')` is `false` — this
project's own deliberate default, "to prevent gadget chain attacks if your
APP_KEY is leaked." Every store built on PHP's `unserialize()` (the
`database` driver included) then passes `allowed_classes: false` to it,
which silently discards the class of *any* cached object and replaces it
with `__PHP_Incomplete_Class` — not at write time, and not as an error, only
as a different, useless object the next time it is read. A method typed to
return a real class or a `Collection` of them then fails with a `TypeError`
on the very read the cache exists to serve. `CachedCourierGateway` was
caching `Collection<CourierOffice>` and `DeliveryQuote` objects directly,
which can never survive a second read under this config, in any environment
that uses it — not a flaky edge case, a guaranteed failure on cache hit.

**Fix.** Cache plain arrays, never DTOs. Every DTO under `App\Support\Courier`
has only public readonly scalar properties, so `(array) $dto` and
`new Dto(...$row)` round-trip cleanly through a value that
`serializable_classes: false` has no reason to touch — see
`CachedCourierGateway::cities()`/`offices()`/`quote()`.

**Why it recurs.** `tests/Feature/Support/Courier/CachedCourierGatewayTest.php`
passed throughout — `phpunit.xml` sets `CACHE_STORE=array` for the whole
suite, and the `array` store keeps live PHP values in an in-memory array with
`'serialize' => false` (`config/cache.php`), so it never round-trips through
`serialize()`/`unserialize()` at all. Every test environment in this project
structurally cannot reproduce this class of bug; only the real `database`
store (`CACHE_STORE` unset, or `=database`, as `.env` has it outside testing)
does. This is the same shape as the Blueprint/factory entry in
[data-and-factories.md](data-and-factories.md) — static checks and the test
suite were green throughout, and nothing short of exercising the real cache
store surfaces it.

**Prevention.** Any new code that caches a value more complex than a scalar
or a plain array must be checked against a real `database`-or-file-backed
cache store by hand — `php artisan tinker` against the running container,
calling the method twice, is enough, since the bug never appears on the
first (cache-miss) call. Do not trust `CachedCourierGatewayTest`'s green run
as proof that caching works end to end; it proves the *decision* of what to
cache and what to bypass, never the *serialization* of what gets cached,
because the `array` store makes that half of the class permanently
untestable from within this suite.

---

## `pest --dirty` needed a mirrored worktree mount, not just `.git`; `--tia`'s guard is bypassable but its results are not trustworthy here

**Symptom.** Inside the `app` container, `git --version` works fine
(`/usr/bin/git`, present on the image), but `pest --dirty` and `pest --tia`
both fail immediately with `Pest\Exceptions\MissingDependency: The [Filter
by dirty files] feature requires [git]. Please install it and try again.` —
a message that reads like git is missing, when it plainly is not.

**Cause, part 1 — no `.git` reachable at all.** `git -C /var/www/html
status` fails with `fatal: not a git repository (or any parent up to mount
point /var/www) — Stopping at filesystem boundary`. Every service in
`docker-compose.yml` mounted `./src:/var/www/html` — only `src/`. The
actual `.git` directory lives at the **repository root**, one level above
`src/` (`CLAUDE.md`: "the app lives in `src/`, not at repo root"), so it
was never mounted into any container at all.

**Cause, part 2 — mounting `.git` alone is not sufficient, confirmed live.**
The obvious fix — bind-mount just `.git` at a path git expects relative to
`/var/www/html` — resolves *a* repository, but the wrong shape for what
Pest needs. `git`'s own worktree root then becomes the parent of wherever
`.git` was mounted, while `/var/www/html` only ever holds `src/`'s own
files with no sibling directories — so every tracked path
(`src/app/...`, `src/tests/...`) reads back as deleted, because nothing in
the container answers to those names relative to `/var/www/html`.
Overriding `GIT_WORK_TREE` to point at `/var/www/html` "fixes" a bare
`git status` run by hand, but does not fix `pest --dirty`: Pest's own
`GitDirtyTestCaseFilter` shells out to `git status --short -- '*.php'`
with no `-C` and no env override of its own, inheriting whatever directory
`pest` was invoked from, and computes each test's "relative path" by
stripping its own `projectRoot` — independent of whatever `GIT_WORK_TREE`
was set to. A git process reporting `src/tests/...`-prefixed paths (because
the real index holds `src/`-prefixed paths) can never match Pest's own
`tests/...`-relative expectation. No environment variable closes that gap;
the two halves are structurally comparing against different roots.

**Fix that actually works, confirmed live.** Mount the **whole repo root**,
not just `.git`, at a sibling path (`/var/www/repo`), so `.git` and `src/`
sit next to each other inside the container exactly as they do on the
host — plus the `vendor`/`node_modules` named volumes a second time, at
`/var/www/repo/src/vendor` and `.../node_modules`, so `pest` itself is
runnable from there. Then run `pest --dirty` from **inside** that mirrored
worktree (`cd /var/www/repo/src`), where git discovers `.git` by walking up
two directories on its own — no `GIT_WORK_TREE` override needed at all —
and reports every path relative to `/var/www/repo/src`, which is exactly
where `pest`'s own `projectRoot` sits. That is the one shape where both
halves agree.

Two more things had to be baked into the image itself, or the same error
resurfaces on every container recreate (`git config --global` writes to
`$HOME`, which a bind-mounted container has none of persistently):

- `git config --system --add safe.directory '*'` — a bind mount is owned
  by the host user's uid, which the container's root does not recognise as
  its own, and git refuses an untrusted repository by default
  (CVE-2022-24765's fix).
- `ENV GIT_DISCOVERY_ACROSS_FILESYSTEM=1` — a bind mount can present as a
  different filesystem (`st_dev`) than its parent directory even though
  both live under the same host path, and git's default "stop at the first
  filesystem boundary" walk-up refuses to cross that even when `.git` is
  right there.

Both now live in `docker/php/Dockerfile`, immediately after `git` is
installed — system-wide and image-baked, surviving every recreate.
`docker-compose.yml`'s `app` and `playwright` services both carry the
`/var/www/repo` mount.

**Verified working, `--dirty` only:**

```bash
docker compose exec app sh -c "cd /var/www/repo/src && ./vendor/bin/pest --dirty"
```

Dirtying one test file and running this narrowed correctly to exactly that
file's tests; a clean tree correctly reported "No dirty tests found." Both
directions confirmed live, not assumed from the config alone.

**`--tia` is not usable here — but not for the reason first written down,
and the correction matters.** Out of the box, `pest --tia` throws
`Pest\Exceptions\TiaRequiresRepositoryRoot`:

> Tia mode requires the project root to be the git repository root, but
> this project sits in the subdirectory `[src]` of a larger repository.
> Please give it its own repository to use Tia.

**[Corrected 2026-09-12]** This entry previously claimed that check was
unconditional and that "no mount shape, no env var, no git config" could
satisfy it. That was wrong, and it was wrong in the specific way this
troubleshooting tree exists to catch: the conclusion was generalised from
the *`--dirty`* investigation without testing TIA's own guard.

What the guard actually does (`Plugins/Tia.php` ~line 825):

```php
$subdirectoryPrefix = $this->gitSubdirectoryPrefix($projectRoot);
if ($subdirectoryPrefix !== null) { Panic::with(new TiaRequiresRepositoryRoot(...)); }
```

`gitSubdirectoryPrefix()` is `new Git($projectRoot)->subdirectoryPrefix()`,
which is `git rev-parse --show-prefix` run with CWD at Pest's project root,
returning `null` when the output is empty. So the real condition is
"`--show-prefix` must be empty," not "the app must own the repository" —
and git lets you declare a worktree root that is not the directory holding
`.git`. Measured, from `/var/www/html`:

| Config | `--show-prefix` | Guard |
|---|---|---|
| default (no env) | `fatal: not a git repository` | fails earlier, on git itself |
| `GIT_DIR=/var/www/repo/.git GIT_WORK_TREE=/var/www/html` | *empty*, exit 0 | **passes** |

The three downstream prerequisites pass too, in that same configuration:
`hasCommits` yes, remote `origin` present, default branch resolving to
`refs/remotes/origin/main`. So TIA can be made to start.

**It should still not be used, because what it produces is silently
wrong.** The index holds `src/`-prefixed paths — that is where these files
genuinely live in the repository — while `GIT_WORK_TREE=/var/www/html`
declares a worktree root one level below them. Under exactly the config
that passes the guard:

```
$ git status --short -- '*.php'
 D src/app/Actions/Cart/AddToCart.php        # every tracked file "deleted"
$ git diff --name-only HEAD~1
.github/PULL_REQUEST_TEMPLATE.md             # repo-root-relative, outside src/
$ git ls-files | head -1
.claude/hookify.ci-shard-reminder.local.md
```

`ChangedFiles::since()` issues `git diff --name-only --no-renames
$sha..HEAD` and hands those paths on; `ChangedFiles::currentHash()` and
`SourceScope::fromProjectRoot()` both resolve them as
`$projectRoot . '/' . $relativePath` — i.e. `/var/www/html/src/app/...`,
which does not exist. Every changed file resolves to a missing path, no
graph edge matches, and TIA has nothing to narrow with. It does not crash;
it selects everything, or selects wrongly, while looking fast and green.
**A test selector that silently over- or under-selects is worse than no
selector**, because the run still reports success — the same class of
failure as a scanner that never reached the target.

Making TIA genuinely sound here would require the index itself to be
rooted at `src/` — i.e. giving `src/` its own repository, which
contradicts the deliberate `src/`-inside-a-docs-and-tooling-repo layout
`CLAUDE.md` chose. That trade is a real decision, not a config tweak, and
belongs in an ADR if anyone wants to make it.

`--dirty --tia` together silently falls back to `--dirty` alone (Pest's own
message: "TIA does not apply to partial runs — running the selected tests
directly"), which is why testing the combination first read as working when
only `--dirty`'s half of it was.

**Why it recurs.** Any future service that runs `pest` (a new CI job, a
second dev container) needs the same three things — the `/var/www/repo`
mount (plus mirrored `vendor`/`node_modules`), the two Dockerfile-baked git
settings, and being invoked from inside the mirrored worktree, not from
`/var/www/html` — or `--dirty` breaks again in exactly this way.

**Prevention.** Before documenting or relying on any git-dependent Pest
flag as part of the normal workflow, verify it against `docker compose exec
app <command>` specifically, in both directions (a real dirty file, and a
clean tree) — not just that the command exits 0.

And the lesson this entry had to learn twice: **"the tool refuses" and
"the tool cannot be made to work" are different claims, and so are "the
tool runs" and "the tool is correct."** The first version of this entry
read a refusal message, inferred an unconditional constraint, and wrote
"no mount, env var, or git config satisfies this" — without running the
one command (`git rev-parse --show-prefix`) that the guard actually
consults. The guard turned out to be satisfiable in about a minute. Had
the investigation stopped at *that* discovery, the entry would have been
wrong in the opposite and more dangerous direction: recommending a config
whose diffs resolve to non-existent paths and whose test selection is
therefore meaningless. Push a "cannot" claim to the command the code
actually runs, then push the resulting "can" claim to whether the output
is *sound* — neither half alone is the answer.

---

## The `db` container exits 126 on a fresh volume, and Pest then can't reach `amazoff_test`

**Symptom.** On Windows, `docker compose up` after `docker compose down -v`
fails with `dependency failed to start: container ...-db-1 exited (126)`.
Every service that depends on `db` never starts. `docker compose logs db`
ends with:

```
/docker-entrypoint-initdb.d/01-test-database.sh: /bin/bash^M: bad interpreter: No such file or directory
```

Skipping the wipe and reusing the old volume hides the crash but produces
the *other* failure instead — the whole suite failing with `Access denied
for user 'sail'@'%' to database 'amazoff_test'`, since the script that
creates that database is exactly the one that never ran.

**Cause.** `src/.gitattributes` normalizes line endings with
`* text=auto eol=lf`, but its scope is `src/` only.
`docker/mysql/init/01-test-database.sh` sits at the repo root, outside that
scope, and there was no root `.gitattributes`. A clone made with Git's
Windows default `core.autocrlf=true` therefore writes that file with CRLF,
making the shebang literally `/bin/bash\r` — a path that does not exist.
MySQL's entrypoint reports it only as exit code 126.

**Fix.** A root [`.gitattributes`](../../../.gitattributes) pins shell
scripts to LF for the whole repository:

```
*.sh text eol=lf
```

An existing checkout keeps its CRLF copy until the file is rewritten, so
re-take it once, then rebuild the volume so the init script runs:

```bash
rm docker/mysql/init/01-test-database.sh
git checkout -- docker/mysql/init/01-test-database.sh
file docker/mysql/init/01-test-database.sh   # must NOT say "CRLF line terminators"
docker compose down -v && docker compose up -d
```

**Why it recurs.** Nothing in the repository fails on a CRLF shell script
until a Linux container tries to *execute* one, and this repo has exactly
one such script, reached only when MySQL initializes an empty volume. So it
is invisible on Linux and macOS, invisible to CI (whose MySQL service needs
no init script), and invisible on Windows until the day someone runs
`down -v`. Its two symptoms also look unrelated to each other and to line
endings: a bare exit code, or a credentials error.

**Prevention.** The root `.gitattributes` covers every `*.sh` added later,
not just this one. Any executable dropped into `docker/` that is *not*
named `*.sh` — a bare `entrypoint`, a Python hook — needs its own line
there, because the extension is what the rule keys on. Note that
`down -v` also wipes the `vendor` and `node_modules` named volumes, so
`composer install` has to be re-run afterwards regardless.

## After pulling the `online-store/` → `src/` rename, the editor reports ~9,000 uncommitted changes

**Symptom.** Pulling `main` across the directory rename leaves an editor
showing thousands of pending changes attributed to you — VS Code's SCM
badge reads about 9,000 — even though you made none. `git stash list` is
empty and `git status --short` shows a single line:

```
?? online-store/
```

**Cause.** Git moves *tracked* files when a rename lands. It never touches
untracked or ignored ones, because it does not know they exist. Everything
ignored inside the old directory — `vendor/`, `node_modules/`,
`public/css/filament/`, `storage/`, `bootstrap/cache/`, `.env`,
`database/database.sqlite` — therefore stayed at the old path while the
tracked files moved to `src/`.

Those leftovers were only invisible because the rules hiding them live in
`src/.gitignore`, whose patterns are relative to `src/`. At
`online-store/` nothing matches them any more, so ~9,000 files flip from
ignored to untracked in one commit. Git collapses that to one `??` line;
editors count the files underneath it and label them as the user's own
changes, which is what makes it read like lost work.

**Fix.** Nothing is lost and nothing needs recovering. Rescue the three
things in there that are not regenerable, then delete the directory:

1. `.env` — `src/.env` does not exist after the rename. Build the new one
   from `src/.env.example` rather than copying the old file, which is
   missing keys added since, and correct the two values the rename
   invalidated: `DB_DATABASE` (now `amazoff`) and `APP_NAME`.
   [`explanation/secrets-and-env.md`](../../explanation/secrets-and-env.md)
   governs how that file is handled.
2. Anything under `storage/app/{public,private}/` — uploaded article and
   product imagery, named by ULID and referenced by database rows.
3. Nothing else. `vendor/`, `node_modules/` and `public/` assets come back
   from `composer install` and `php artisan filament:assets`;
   `database/database.sqlite` is dead weight, since this project runs
   MySQL.

Then `rm -rf online-store/`, and re-run the setup sequence in
[`README.md`](../../../README.md) against `src/`.

**Why it recurs.** Any future move of a directory that contains ignored
build output reproduces it exactly, and the giveaway is easy to miss:
`git status --short` printing one line while the editor claims thousands.
The editor is counting files, not changes, and untracked is not modified.

**Prevention.** Read `git status --short` before believing an editor's
count, and check `git stash list` before believing the word "stashed" in
any UI. When a rename like this is planned, saying so in the PR body —
along with which ignored paths will be stranded — costs one sentence and
saves everyone pulling it the same investigation.

---

## Railway deploy: eleven failures, one Dockerfile

**Symptom.** A custom root `Dockerfile` (Node assets → Composer → Alpine
`php:8.4-fpm` runtime, nginx and php-fpm under supervisord) for deploying to
Railway ([ADR-0023](../../adr/0023-railway-beta-deploy-target.md)) failed
eleven consecutive deploys. Every fix addressed a real, verified defect;
every fix was followed by a new failure one layer further in. Ended by
abandoning the custom image entirely for Railway's own Railpack builder
([ADR-0024](../../adr/0024-railpack-over-custom-image.md)).

**Cause, in the order found — each a distinct bug, not a retry of the same
one:**

1. **`composer install` in the vendor stage used the bare `composer:2`
   image**, whose PHP has no `intl` — a production requirement of
   `filament/support`. Composer aborted before installing a single package:
   `requires ext-intl * -> it is missing from your system`. Fixed by
   building the vendor stage `FROM php:8.4-fpm-alpine` (the same base as the
   runtime) with the same extensions installed, so Composer resolves against
   the platform that will actually run the code. This incidentally fixed a
   second latent bug: `composer:2` ships PHP 8.5 while `composer.json`
   requires `^8.4`, so dependencies were being resolved against the wrong
   PHP entirely.

2. **`RUN --mount=from=composer:2,source=...,target=...` to copy the
   composer binary in** built fine under local BuildKit and failed on
   Railway's builder in six seconds, before a single layer ran:
   `dockerfile invalid: flag '--mount=...' is missing a type=cache argument
   (other mount types are not supported)`. Railway's Metal builder supports
   only `type=cache` mounts; a bind mount from another image is
   unavailable there. Fixed with `COPY --from=composer:2 ... && rm` in one
   layer instead. **This is the one to remember: a Dockerfile can be
   locally green and rejected by the platform's builder outright, before
   any code in it runs.** Local BuildKit is the more permissive of the two.

3. **`apk add --virtual .build-deps ... && apk del .build-deps` in the
   vendor stage installed only the `-dev` packages**, not the runtime
   shared libraries they wrap (`icu-libs`, `libzip`, ...). The extensions
   compiled successfully, wrote their `.ini` files, and could not load —
   Composer reported `ext-intl ... is missing from your system` while
   listing `docker-php-ext-intl.ini` among its own loaded config files, one
   line apart. **That self-contradiction — "missing" and "loaded" in the
   same error block — is the signature of a stripped runtime library, not
   of a missing extension**, and is worth grepping for specifically before
   trusting Composer's platform-requirement wording at face value. Fixed by
   installing the runtime libraries (not just `-dev`) before the build-deps,
   verified with a build-time assertion (`php -m | grep -q '^intl$'`) so it
   cannot regress silently.

4. **The runtime stage's start command ran `composer dump-autoload` without
   composer installed there**, and separately booted Laravel before
   `storage/framework/views` and sibling scratch directories existed.
   `.dockerignore` excluded those directories (correctly, to keep host
   cache out of the image) but that also removed the committed `.gitignore`
   keeper files that are how the repository normally preserves them as
   empty — so the directories didn't exist at all. Laravel's error for the
   second half, `Please provide a valid cache path`, names neither the
   missing directory nor the reason. Fixed by copying composer in
   temporarily (`COPY --from=composer:2 ... /usr/local/bin/composer`,
   removed in the same layer after use) and recreating the scratch
   directory tree explicitly before anything boots the framework.

5. **The committed schema dump made `migrate` shell out to a `mysql`
   client binary the runtime image didn't have.**
   `database/schema/mysql-schema.sql` is committed for local speed —
   `migrate` loads it in one shot instead of replaying 68 migrations — but
   Laravel's `MySqlSchemaState` does that by invoking the `mysql` CLI
   directly. The runtime image had no such client (dismissed early as a
   "test-harness concern," which was wrong for the deployed image).
   Symptom: `Loading stored database schemas ... FAIL`, `sh: mysql: not
   found`, exit 127 — arriving *after* `Creating migration table ...
   DONE`, so it reads like a working connection that then broke, not a
   missing binary. Installing `mysql-client` (see #6) fixed this specific
   symptom, and #7 explains why the client route was abandoned anyway.

6. **Alpine's `mysql-client` is MariaDB's, and it verifies TLS certificates
   by default since 11.x.** Railway's managed MySQL presents a self-signed
   certificate, so the client refused it: `ERROR 2026 (HY000): TLS/SSL
   error: self-signed certificate in certificate chain`. The fix —
   `ssl-verify-server-cert=0` in a client config file — has its own trap
   worth flagging on its own: **the first attempt wrote it to
   `/etc/my.cnf.d/99-skip-ssl-verify.cnf`, the conventional drop-in path,
   and Alpine's client build has no include-directory.** `mysql --help`
   reports it reads only `/etc/my.cnf /etc/mysql/my.cnf ~/.my.cnf`. The
   drop-in would have built successfully, been silently ignored, and left
   the TLS error byte-identical — a fix that looks applied and does
   nothing. Caught by checking which files the client actually reads
   (`mysql --help`) and confirming with `mysql --print-defaults`, which
   echoes the options actually in effect, rather than inferring success
   from the absence of a new error.

7. **MariaDB's client cannot authenticate to MySQL 8 at all.** With TLS
   verification off, the very next attempt failed differently: `ERROR 1045
   (28000): Plugin caching_sha2_password could not be loaded:
   /usr/lib/mariadb/plugin/caching_sha2_password.so: No such file or
   directory`. MySQL 8 authenticates with `caching_sha2_password` by
   default; MariaDB's client has no such plugin, and Alpine ships only
   MariaDB's. **This is structural, not configurable — no flag or config
   file closes it.** This is what ended the patch-one-symptom-at-a-time
   approach to the `mysql`-client route: fixed by excluding
   `database/schema/` from the build context entirely
   (`.dockerignore`), so `migrate` replays all 68 migrations through PDO
   (`pdo_mysql`, compiled into the image, speaks the MySQL 8 protocol
   natively) and never shells out to any client. Verified by confirming
   `database/schema/` is absent from the built image and that all 68
   migrations are still present to replay.

8. **The healthcheck then failed for reasons that resisted five further
   fix attempts — `PORT` as a service variable, the domain's `targetPort`,
   removing the healthcheck outright, and binding nginx on IPv6 as well as
   IPv4 — none of which were the actual cause,** though each closed a real
   gap (IPv6 binding in particular: Railway's proxy reaches containers over
   IPv6, and Alpine nginx with a bare `listen ${PORT};` binds IPv4 only,
   which is worth fixing regardless of whether it was this failure's
   cause). The container built, migrated, and created successfully every
   time; only the healthcheck ever failed, with `service unavailable` on
   every attempt. **The likely proximate cause, never fully confirmed:**
   Railway documents that a Dockerfile-based service's start command runs
   in **exec form, not through a shell** — `a && b && c` is invalid there,
   even though the equivalent `docker run --entrypoint sh -c "a && b && c"`
   used in every local reproduction wrapped it in a shell and never
   exercised that failure path. By the time this was found, the decision
   had already been made to stop debugging the image and switch to
   Railway's own Railpack builder instead — see below.

**Why it recurs, and the pattern worth keeping even though the custom image
is gone:** a local `docker build` and `docker run` are more permissive than
Railway's specific builder and runtime in ways invisible from the local
side — mount syntax, apk runtime-library stripping, exec-vs-shell start
commands, IPv4-only binding. **A build or container that is green locally
proves the image is internally consistent. It does not prove the platform
will accept or run it.** Any future Dockerfile-based deploy — to Railway or
anywhere else — should expect this class of gap and verify against the
actual target rather than trusting local reproduction, however thorough.

**Fix, ultimately.** Not another Dockerfile iteration.
[ADR-0024](../../adr/0024-railpack-over-custom-image.md) drops the custom
image and lets Railway's Railpack builder detect and build the Laravel app
directly — no nginx, no supervisord, no manual `$PORT` handling. Every one
of the eight failure classes above lived in code that approach deletes.
Two Railpack-specific traps found while switching, both from reading
Railpack's own provider source rather than assuming: it runs
`composer install --no-scripts`, so `post-autoload-dump` (and therefore
`filament:upgrade`) never runs during build; and it collects PHP extensions
from `composer.json`'s `require` section plus an env var, never from
`composer.lock` — this project's `composer.json` declares no `ext-*`
requirements at all, so the extension list has to be set explicitly via
`RAILPACK_PHP_EXTENSIONS` rather than relying on auto-detection.

**Prevention.** For any Dockerfile aimed at a platform you don't control:
budget for the build and the runtime to differ from your local Docker in
ways that only surface on the platform, verify each fix against the actual
target rather than a local reproduction, and if failures keep surfacing one
layer deeper after each fix — as opposed to the same failure recurring —
that pattern is itself information: it says the approach is the problem,
not the current line.

---

## The `queue` container crash-loops, and every queued email fails with "Lost connection"

**Symptom.** `docker compose ps` shows `queue` cycling through
`Restarting` with an increasing backoff (a few seconds, then tens of
seconds, then over a minute). `docker compose logs queue` shows the same
shape repeating: a mail job (`OrderPlaced`, `NewsletterConfirmation`, …)
starts, fails in well under a second with "Lost connection," and the
worker itself stops — Laravel's queue worker exits on certain connection
failures rather than just failing the one job, which is what turns "one
job can't send mail" into "the whole container restarts." Anything that
queues a lot of mail at once — `demo:seed`'s 140+ orders, in particular —
turns this from an occasional retry into a tight crash loop, because the
backlog is large enough that a new failing job is always waiting when the
container comes back up.

**Cause.** `mailpit` (`docker-compose.yml`'s mail-catcher service,
`MAIL_HOST` in dev) was not running — `docker compose ps mailpit` returned
nothing at all, not even a stopped container. Nothing starts it
automatically as a dependency of `app` or `queue`; it has to be brought up
explicitly, and a session that never ran `docker compose up -d` against
the full stack (or that ran it before `mailpit` existed in the compose
file) never gets it.

**Fix.**

```bash
docker compose up -d mailpit
```

No restart of `queue` needed — once `mailpit` is reachable, `queue`'s next
scheduled retry (per its own backoff) succeeds and the container stabilises
on its own. A large backlog drains in well under a second per job once
unblocked; watch `docker compose logs queue --tail 10` for `DONE` instead
of `FAIL` to confirm.

**Why it recurs.** `mailpit` costs nothing at rest and gives no positive
signal that it is needed — the app itself never touches it, only queued
mail does — so a session that never seeds a lot of orders in one go can
run for hours without noticing it is missing. The crash loop looks alarming
(a container endlessly restarting) but the actual cause is one line away
from the symptom: check `docker compose ps` for anything *not* listed as
running before assuming the queue worker itself is broken.

**Prevention.** Nothing enforces this today — a healthcheck or `depends_on`
relationship from `queue` to `mailpit` would surface the gap as "queue
won't start" rather than "queue crash-loops once something queues mail,"
which is a clearer failure to diagnose. Not added here since it would
change `docker-compose.yml`'s service dependencies outside this fix's
scope; worth doing the next time this file is touched for another reason.
A background container crash-looping like this is also a plausible
contributor to unrelated flakiness elsewhere — see
"`pest --testsuite=Feature,Unit,Concurrency` fails a handful of unrelated
`Feature` tests" in
[`concurrency-and-testing-races.md`](concurrency-and-testing-races.md),
found the same day this entry was written, before the cause here was
known.
