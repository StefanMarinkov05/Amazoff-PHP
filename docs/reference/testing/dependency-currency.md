# Dependency currency — outdated packages and known advisories

Part of the [testing reference](README.md). Separate from
[performance-testing.md](performance-testing.md) and
[security-tooling.md](security-tooling.md) on purpose: this is a package-age
and CVE check, not a load test or an application-level scan, and it changes
on a completely different cadence — whenever upstream ships a release, not
whenever this codebase's own code changes.

**Date of record:** 2026-09-14.

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

## Version currency — 24 direct Composer dependencies

`composer outdated --direct` (skips transitive dependencies — a package this
project doesn't choose directly and can't act on alone). Two are a major
version behind; the rest are routine patch/minor drift, normal for any
actively-maintained stack and not worth tracking individually:

| Package | Installed | Latest | Gap |
|---|---|---|---|
| `filament/filament` | v4.12.6 | v5.8.1 | **major** |
| `livewire/livewire` | v3.8.3 | v4.4.4 | **major** |
| `astrotomic/laravel-translatable` | v11.17.0 | v11.17.1 | patch |
| `larastan/larastan` | v3.10.0 | v3.12.1 | minor |
| `laravel/boost` | v2.5.5 | v2.8.1 | minor |
| `laravel/pao` | v1.1.3 | v1.1.5 | patch |
| `laravel/pint` | v1.30.4 | v1.32.1 | minor |
| `mockery/mockery` | 1.6.12 | 1.6.15 | patch |
| `pestphp/pest` | v5.1.2 | v5.1.4 | patch |
| `phpunit/phpunit` | 13.3.1 | 13.3.3 | patch |
| `saloonphp/saloon` | v4.0.0 | v4.0.1 | patch |
| `spatie/laravel-activitylog` | 5.0.0 | 5.1.1 | minor |
| `stripe/stripe-php` | v21.1.1 | v21.3.2 | minor |

The other 11 direct dependencies report current.

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
