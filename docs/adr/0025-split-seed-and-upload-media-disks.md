# ADR-0025: Split seed and upload media disks, routed by path prefix

Status: Accepted
Date: 2026-09-14 · Deciders: Stefan Marinkov

Revisits [ADR-0023](0023-railway-beta-deploy-target.md)'s "Uploads are
accepted as ephemeral, for now" decision, whose own stated trigger —
*"anyone uploads an image they expect to keep"* — this ADR treats as met.
Nothing else in ADR-0023 changes: Railway remains the platform, `APP_ENV=demo`
stands, `trustProxies` stands.

## Context

Every image — the 182 demo product photos and 23 demo article covers
committed into the repository, and anything an admin uploads through the
Filament panel — has lived on one Laravel disk (`public`,
`storage/app/public`) since the storefront was built. Railway's filesystem is
ephemeral: a container restart or redeploy discards anything written to it at
runtime. The demo images survive because they are committed to git and
therefore baked into the container image at build time; an admin-uploaded
image is not, and is lost on the next deploy. This was accepted, not missed —
ADR-0023 named it explicitly and deferred it.

Two facts, established by inspecting the live box and the vendor source
rather than assumed from the existing docs, turned out to matter more than
expected:

**ADR-0023's stated mount path is stale.** It names
`/var/www/html/storage/app/public` as where a future volume should attach.
That path is a leftover from the custom Dockerfile [ADR-0024](0024-railpack-over-custom-image.md)
replaced — under the current Railpack/FrankenPHP build the application root
is `/app`, confirmed via `railway ssh`. A volume mounted at the old path
would attach successfully and simply never be read; nothing would fail
loudly. Real, verified layout: app root `/app`, storage
`/app/storage/app/public`, symlink `/app/public/storage ->
/app/storage/app/public`.

**Seed and upload content already occupy disjoint path prefixes.** Every
seeded row's path starts with `demo/` — `demo/clm0001-main.jpg` for products,
`demo/articles/laptop-guide.jpg` for articles — written by
`database/fixtures/demo*/*.json` and by the `demo:fetch-images`/
`demo:fetch-article-images` commands. Every uploaded row's path starts with
`ProductImage::DIRECTORY` (`product-images/`) or `Article::IMAGE_DIRECTORY`
(`articles/`), written by Filament's upload fields. `storage/app/public/.gitignore`
already commits only `demo/**`; on the live box, before this change,
`product-images/` and `articles/` did not exist at all, because nothing
uploaded there had ever survived a redeploy. **There was no data to
migrate.**

## Decision

**Two disks, and a row's path prefix — not a probe — decides which one it is
on.**

`config/filesystems.php` gains a `media` disk, rooted at
`storage/app/media`, and a `media_disk` config key read from `MEDIA_DISK`
(default `media`). The existing `public` disk is untouched in every
respect — driver, root, URL, the `storage` symlink — its *meaning* simply
narrows to "seeded content, baked into the image."

`ProductImage` and `Article` each gain a `disk()`/`imageDisk()` method:

```php
public function disk(): string
{
    return str_starts_with((string) $this->path, self::SEED_DIRECTORY.'/')
        ? self::SEED_DISK
        : self::uploadDisk();
}

public static function uploadDisk(): string
{
    return config('filesystems.media_disk');
}
```

`servableUrl()` (`ProductImage`) and `ResolveArticleImage::urlOrNull()` both
call `disk()`/`imageDisk()` instead of a hardcoded constant, and are
otherwise unchanged — one `Storage::exists()` call, one `Storage::url()`
call, same as before this change.

**Resolving by prefix rather than probing both disks was the load-bearing
choice**, for three reasons:

1. `servableUrl()` performs exactly one disk check today. A fallback shaped
   "try the upload disk, then try the seed disk" would double that call on
   every render that misses the first disk — cheap as a local stat, but a
   second network round-trip once `MEDIA_DISK` ever points at S3.
2. It is deterministic. If the same filename existed on both disks (a
   coincidence, but not one the schema forbids), a probe-based fallback would
   resolve to whichever disk happened to be checked first — an accident of
   implementation order standing in for a real answer.
3. It degrades correctly on the exact day this ADR exists for: the first
   deploy after the volume is mounted, when the upload disk is empty and
   every seeded row must still resolve. Prefix routing sends every seeded row
   straight to `SEED_DISK` regardless of what the (empty) upload disk
   contains; a row whose file really is missing still falls back to the
   existing placeholder, unchanged.

### Mount path: `/app/storage/app/media`, not inside `storage/app/public`

Deliberately outside the seed disk's own root. A volume mounted *at*
`storage/app/public` (nesting it under the seed tree, e.g. at `.../public/uploads`)
would still work today, but makes an empty-volume shadowing accident a
one-directory-away mistake forever. Mounting a fully separate root makes that
class of mistake structurally impossible rather than merely avoided by
convention.

### No interface, no enum

`MEDIA_DISK=media` today, `MEDIA_DISK=s3` when object storage is
provisioned — both disks already speak the identical `Illuminate\Filesystem\
FilesystemAdapter` contract (`put`/`exists`/`url`/`delete`), and Filament's
own `->disk()` accepts a plain string. Wrapping that in a bespoke interface
would be exactly the "interface over a single implementation" ADR-0001
already rejected for Stripe — Flysystem is the abstraction here, the same
way Laravel's own SDK is Stripe's. A `MediaDisk` enum was considered and
rejected for the same reason: its only behaviour would be re-reading
`config()`, which the disk-resolution methods already do directly.

### Filament display sites: closures, not a third fallback path

`ImageColumn`/`ImageEntry`'s `->disk()` accepts `string|Closure|null`
(confirmed against `filament/tables` and `filament/infolists` source). Every
per-record display site (`ArticlesTable`, `ArticleInfolist`,
`ProductImagesRelationManager`'s image column) passes
`fn ($record) => $record->disk()` — the same routing logic, not a second
copy of it. Two sites render N images from a *relation*
(`ProductInfolist`'s and `ProductVariationsRelationManager`'s `stacked()`
columns) where a per-record closure cannot discriminate per image; those use
`->getStateUsing()` to map the relation straight to resolved
`servableUrl()` strings and drop `->disk()` entirely. Verified live,
end-to-end, with a real upload sitting alongside seeded images in the same
table: both render correctly, no broken thumbnails.

### A pre-existing gotcha this surfaced

`FetchDemoArticleImages` wrote its downloaded files under `Article::IMAGE_DIRECTORY`
(`articles/{slug}.jpg`) while `database/fixtures/demo-articles/*.json` had
always declared `demo/articles/{slug}.jpg` — the command's own written path
disagreed with the fixture convention it was meant to backfill, and would
have sent seeded article covers to the upload disk under this ADR's routing.
Fixed to write `Article::IMAGE_SEED_DIRECTORY` instead. Not this ADR's
subject, but this ADR's routing rule is what made the disagreement matter
enough to fix. The stress seeders (`CatalogueStressSeeder`,
`DeepCatalogueStressSeeder`) had the identical bug — their generated
placeholder image lived at `product-images/stress-placeholder.png`, an
upload path for what is unambiguously seed content — fixed the same way.

## Consequences

**+** An image uploaded through the admin panel survives a redeploy for the
first time since the beta existed — the concrete thing ADR-0023 could not
yet demonstrate.

**+** The rollback is one environment variable. `MEDIA_DISK=public` returns
every upload to the pre-this-ADR combined disk with no code change, and the
volume is left in place, not deleted.

**+** The eventual S3 migration is the same one variable, plus
`composer require league/flysystem-aws-s3-v3` and populating the bucket —
genuinely deferred work, not a design gap being papered over.

**−** Under a remote `MEDIA_DISK` (S3), `servableUrl()`'s single
`Storage::exists()` call becomes a `HeadObject` network round-trip
(~20–100ms) per image rendered. At `/catalogue`'s measured scale
(`reference/testing/performance-testing.md`: 203 queries, 2267ms at 100k
products), a per-image network call on every render is not acceptable as-is.
Deliberately not solved here — the right mitigation (skip the check
entirely and rely on a CDN/`onerror` fallback, or cache existence with a
TTL) is only decidable once S3 is real and its actual latency is measured
against this codebase, not guessed at now. **Trigger to revisit:** the day
`MEDIA_DISK` is set to anything other than a local disk.

**−** `RemoveProductImage` must resolve `$image->disk()` before deleting the
row, not after — the model is not a safe thing to query once
`$image->delete()` has run. Mirrors the existing discipline around capturing
`$path` before the same delete, for the same reason; not a new pattern, but
one more thing to get right at each call site that deletes a `ProductImage`
or an `Article`'s cover.

**−** Local development gains one manual step after this change lands:
existing `product-images/`/`articles/` files under `storage/app/public/`
must be moved to `storage/app/media/` by hand
(`docs/how-to/seed-the-database.md`). Ten gitignored files per developer
machine; a migration command would be ceremony for that volume.

## Alternatives considered

**Probe both disks in `servableUrl()`** (check upload disk, fall back to
seed disk). Rejected: doubles the `exists()` cost under a remote disk,
resolves non-deterministically on a filename collision, and the routing
information already exists for free in the path prefix — probing throws
that away and reconstructs a weaker version of it at read time.

**Mount the volume inside `storage/app/public`** (e.g.
`storage/app/public/uploads`), keeping one Laravel disk with two
subdirectories. Rejected: this is exactly the shadowing risk that motivated
splitting disks in the first place, just one directory level removed rather
than eliminated — an empty volume mounted one level too high still masks
`demo/`. A separate disk root makes the mistake impossible to make by
accident.

**A `MediaDisk` interface/adapter wrapping both disks.** Rejected per
ADR-0001's own reasoning against a Stripe interface: Flysystem already is
the abstraction Laravel provides, and both concrete disks used here
(`local`, `s3`) already implement it identically. An interface here would
wrap an abstraction in another abstraction for no caller that needs to swap
implementations at runtime.

**Accept the two admin `stacked()` columns rendering broken thumbnails for
mixed seed/upload products**, as a documented, low-priority gap. Not
needed: `->getStateUsing()` solved both cleanly, verified live. Recorded
here only because it was the fallback position going in, in case a future
Filament version regresses this and the fallback needs to be exercised for
real.
