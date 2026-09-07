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
per the pool config the image ships. The bind-mounted `online-store/` keeps
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
