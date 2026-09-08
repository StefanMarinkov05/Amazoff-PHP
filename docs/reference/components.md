# Blade components

Every shared, stateless piece of markup on the storefront. One row per
`resources/views/components/**/*.blade.php` file.

This is the *reference* — what exists and what each one is for. For *how* to
write one (the four kinds of view, `@props`, escaping, the `@php` rule),
see `docs/how-to/write-a-storefront-page.md`. For how they fit into a page,
see `docs/explanation/storefront-pages.md`.

A Blade component here is markup and `@props` only: it cannot hold state,
cannot carry `wire:click`, and re-renders with its parent. Anything with
state is a Livewire component under `views/livewire/`, listed in
`docs/reference/testing/ui-tests.md` instead.

| Component | File | Props | What it is |
|---|---|---|---|
| `<x-layouts.app>` | `layouts/app.blade.php` | `title` (optional) | The page shell every full-page Livewire component is wrapped in — `<head>`, the skip link, `<x-site.header>`, `<main>`, `<x-site.footer>`. Livewire looks for exactly this path. A page never renders chrome itself. |
| `<x-site.header>` | `site/header.blade.php` | — | The top nav: the `Amazoff` wordmark, the primary links (with active-state matching), cart badge, account menu. Its wordmark styling is the reference every other "Amazoff" rendering copies. |
| `<x-site.footer>` | `site/footer.blade.php` | — | The footer: link columns, the newsletter signup (`Contact\NewsletterSignup` Livewire island), legal line. |
| `<x-site.prose-page>` | `site/prose-page.blade.php` | `title`, `standfirst` (optional), `updated` (optional) | The shell for the six plain informational pages (`/delivery`, `/payment-information`, `/faq`, `/terms`, `/privacy`, `/cookies`) — heading, optional standfirst and "last updated" line, body typography, back-link. `/about` deliberately does **not** use it (it has its own hero). |
| `<x-money>` | `money.blade.php` | `amount`, `currency` (defaults to `Currency::default()`) | One money amount with its currency symbol. Replaces the bare `€{{ number_format(...) }}` that every price used inline — the symbol was easy to forget (the account order pages did) and the currency was hard-coded even though `orders.currency`/`payments.currency` snapshot a real `App\Enums\Currency`. `amount` is anything numeric (a `decimal:2` value, a `Money`, a string). |
| `<x-checkout.field>` | `checkout/field.blade.php` | `name`, `label`, `type` (default `text`), plus passthrough attributes | One labelled text input for the checkout form — wires `wire:model` to `name`, renders the `@error` message, keeps the ~10 checkout fields from being ten near-identical blocks. |
| `<x-account.order-row>` | `account/order-row.blade.php` | `order` | One row in the account order list (`OrderHistory`). The whole row is a link to `account.orders.show` (`OrderDetails`) — **not** `checkout.confirmation`, which is the post-checkout page and shows less. Shared by the "In progress" and "Completed" groups so a change lands in both. Uses `<x-money>`. |
| `<x-home.product-section>` | `home/product-section.blade.php` | `title`, `subtitle`, `products`, `price` | A titled row of trimmed product cards on the home page — image, brand, name, price. Deliberately lighter than the catalogue grid's card (no rating, no wishlist toggle): the home page is a shop window, not the catalogue. |
| `<x-journal.cover>` | `journal/cover.blade.php` | `article`, `index` (optional) | An article's cover image with its resolved fallback (`ResolveArticleImage`), used on the journal list and article pages. `index` tweaks the treatment for the lead item. |

## When a new one earns its place

Implementation standard #8 — "no oversized Blade files; repeated markup
extracted into components." The trigger is *repetition that is diverging at
the edges*, not repetition alone: `<x-site.prose-page>` exists because six
files were about to drift, `<x-account.order-row>` because two groups render
the same row. A block used once stays inline.

Add the row here in the same change that adds the component, the same way
`docs/reference/actions.md` is updated with a new Action.
