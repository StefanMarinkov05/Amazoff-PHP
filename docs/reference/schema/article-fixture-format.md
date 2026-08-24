# Article fixture format

The JSON shape `ArticleFixtureLoader` consumes and `fixtures:validate-articles`
checks. One document per article. Cross-references — author, category, tags,
related products — are slugs or, for the author, an email, resolved by the
loader the same way `reference/schema/fixture-format.md` resolves a product's
category and brand.

A separate format from the product one, and a separate loader and validator,
because the two aggregates share nothing beyond "read a directory of JSON" —
merging them would make both harder to read.

## Document shape

```json
{
  "title": "Choosing the Right Drill for Your Project",
  "slug": "choosing-the-right-drill",
  "author": "editor@example.com",
  "category": "buying-guides",
  "summary": "Corded or cordless, and how much torque you actually need.",
  "content": "Full article body. Plain text or the RichEditor's own markup.",
  "main_image_path": "demo/articles/drill-guide.jpg",
  "featured": false,
  "seo_title": "Choosing the Right Drill | Amazoff",
  "seo_description": "A buying guide comparing corded and cordless drills.",
  "tags": ["buying-guides", "power-tools"],
  "related_products": ["18v-cordless-drill-driver"],
  "status": "published"
}
```

## Field notes

| Field | Rule |
|---|---|
| `title`, `slug` | required; `slug` unique across the whole fixture set, checked cross-document |
| `author` | required; an email that must already exist as a `users` row — seed accounts first |
| `category` | article category slug, resolved against `article_categories.slug`; omit for uncategorised |
| `content` | required; the article body |
| `tags` | list of tag slugs, resolved against `tags.slug` |
| `related_products` | list of **product** slugs, resolved against `products.slug` — §22's "related products" |
| `status` | one of `draft`, `scheduled`, `published`, `archived`; omit for `draft` |
| `title`, `slug` | ≤ 100 characters |
| `summary`, `seo_description` | ≤ 255 characters |
| `seo_title` | ≤ 100 characters |

No `published_at` field. `PublishArticle` is the single writer of that
column and stamps it itself, at load time, the first time the article
reaches `Published` — a fixture cannot backdate it without going around the
Action that owns it, and this format does not offer a way to.

## What the loader does with one document

1. Insert the article row directly, always as `Draft` regardless of what
   `status` asks for — mirrors `FixtureLoader`'s treatment of images:
   `PublishArticle` is the only writer of `status` and `published_at`, so
   creating a pre-published row would bypass the one Action that enforces
   `ArticleStatus`'s matrix.
2. Attach `tags` and `related_products` as plain pivot rows — no invariant
   on either pivot, same reasoning `attribute_value_product_variation`
   attachment in the product loader gets.
3. If `status` asked for anything but `Draft`, call `PublishArticle` to move
   it there.

### The one detour: reaching `Archived`

`ArticleStatus`'s matrix has no `Draft => Archived` edge — §22's lifecycle
requires passing through `Published` first. A fixture asking for
`"status": "archived"` is therefore driven through `Published` first, then
`Archived`, as two calls. This is decided once in the loader rather than
left for a fixture author to rediscover via
`ArticleTransitionNotAllowedException`.

## Validating and loading

```bash
docker compose exec app php artisan fixtures:validate-articles
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoArticleSeeder"
```

Same ordering trap as the product fixtures: an author email or category
slug can only resolve against rows that already exist. Seed users and
`CatalogueReferenceSeeder`'s article categories first — see
`how-to/seed-the-database.md`.

## What `fixtures:validate-articles` checks

- Required keys present: `title`, `slug`, `content`, `author`.
- `slug` unique across the whole set, not just within one file.
- `author` resolves to a real `users.email`.
- `category`, every `tags[]` entry, and every `related_products[]` entry
  resolve to real rows.
- `status`, if present, is a real `ArticleStatus` value.
- String length limits match the schema.

It does not — and cannot — check that `related_products` names a product
whose fixture has actually been loaded yet; the article loader and the
product loader are independent, so load products before articles that
reference them, or the article load fails on an unresolved slug rather than
the validator catching it in advance.
