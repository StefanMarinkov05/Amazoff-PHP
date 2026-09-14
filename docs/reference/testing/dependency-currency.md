# Dependency currency — outdated packages and known advisories

Part of the [testing reference](README.md). Separate from
[performance-testing.md](performance-testing.md) and
[security-tooling.md](security-tooling.md) on purpose: this is a package-age
and CVE check, not a load test or an application-level scan, and it changes
on a completely different cadence — whenever upstream ships a release, not
whenever this codebase's own code changes.

**Date of record:** 2026-09-14 (updated same day — see "What changed today"
below).

## Known-vulnerability check — clean

```bash
docker compose exec app composer audit
docker compose exec vite npm audit --production
```

**`composer audit`: no security vulnerability advisories found.**
**`npm audit --production`: found 0 vulnerabilities.**

Both check the exact locked versions in `composer.lock`/`package-lock.json`
against their respective advisory databases (Packagist's for Composer, the
npm registry's for Node) — a real, dated result, not an inference from
version numbers.

## Version currency — remaining outdated direct dependencies

`composer outdated --direct` / `npm outdated` (skips transitive
dependencies — a package this project doesn't choose directly and can't
act on alone). Everything not listed here reports current as of today:

| Package | Installed | Latest | Gap |
|---|---|---|---|
| `filament/filament` | v4.12.6 | v5.8.1 | **major** |
| `livewire/livewire` | v3.8.3 | v4.4.4 | **major** |
| `phpunit/phpunit` | 13.3.2 | 13.3.3 | patch |
| `concurrently` (npm) | 9.2.4 | 10.0.5 | **major** |
| `playwright` (npm) | 1.62.1 | 1.63.0 | patch |

`phpunit/phpunit` is pinned by `pestphp/pest` v5.1.4's own constraint
(`composer why-not phpunit/phpunit 13.3.3` shows the conflict) — 13.3.2 is
the newest version Pest currently allows, not an oversight.
`concurrently`'s major was left alone for the same reason as Filament and
Livewire below: no CVE or functional gap forcing it.

### Filament and Livewire — one major version behind, deliberately not bumped

**Do not bump either mid-project, and especially not close to a
presentation deadline.** A major-version upgrade of the framework the
entire admin panel and every Livewire component is built on is exactly the
class of change this project's own working style guards against —
`coding-conventions.md`'s "Generated code is a first draft. It gets read
before it's trusted" and the no-scope-creep discipline apply doubly to a
breaking upgrade with no deadline pressure behind it and everything to lose
if it breaks something silently. Filament 4→5 and Livewire 3→4 are both
major releases with their own migration guides; treat this as a deliberate,
scheduled piece of work for after the deadline, not a "might as well while
I'm here" — no CVE or functional gap is forcing it, per the clean audit
above.

## Re-running this check

```bash
docker compose exec app composer audit
docker compose exec app composer outdated --direct --format=json
docker compose exec vite npm audit --production
```

`npm` is not in the `app` container (PHP-only per `docker/php/Dockerfile`) —
run npm commands against the `vite` service instead.
