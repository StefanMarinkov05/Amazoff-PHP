# Demo showcase order — the 13 cases and why each was picked

The staff-only "Demo order" sort on `/catalogue` shows **exactly these 13
products, and nothing else** — every other filter (search, category, brand,
price, rating, stock, sale) is bypassed entirely once this sort is active,
not merely narrowed further. `App\Livewire\Catalogue\ProductList::
productsQuery()` is where that bypass happens;
`Database\Seeders\Demo\DemoShowcaseOrderSeeder` is what assigns
`products.demo_case_order`/`demo_case_label`, the two nullable columns this
whole feature is built on (`2026_08_30_090000_add_demo_showcase_columns_to_products`).

Facts as of 2026-08-30, verified against `amazoff_demo` directly — every SKU
below was checked to actually exist and actually exhibit the case named,
not assumed from a query that looked right. Two picks were wrong on the
first pass and are recorded below with why, since the same mistake is easy
to repeat if this list is ever re-picked from scratch.

## Who sees it, and how

`ProductList::isDemoModeAvailable()` — `auth()->user()?->canAccessPanel(...)`
— is the single check, called both where the **Demo order** button renders
(the sort bar) and where the query and the product-card badge apply it. A
guest sending `?sortBy=demo_case_order` directly gets no effect: the value
is not in `SORTS`, so `setSortOrder()` refuses it and the query falls back
to the normal `created_at` sort — verified live, not merely reasoned about
(`curl` with a forced query string, and `tests/Feature/Livewire/
ProductListDemoOrderTest.php`'s guest cases).

## The 13, in order

| # | SKU | Product | Case |
|---|---|---|---|
| 1 | `CLM-0001` | Classic Crew Neck T-Shirt | Images on the product **and** on variations; 5 variations across 4 sizes/5 colours with real gaps (no White+L, no Black+XL) — the impossible-combination case, same product |
| 2 | `CLM-0014` | Everyday Sneakers Low-Top | One variation carrying two images |
| 3 | `CLM-0002` | Slim Fit Oxford Shirt | One image linked to two different variations |
| 4 | `WRK-0004` | Steel Toe Cap Safety Boots | Four variations, a real size spread |
| 5 | `PWR-0010` | 18V Cordless 4-Piece Combo Kit | Category (`Power Tools`) is a parent node, not a leaf — and itself holds products directly, alongside its children's |
| 6 | `ELC-0021` | Wireless Game Controller | Multiple approved reviews, mixed ratings (avg 4.33 across 3) |
| 7 | `CLM-0004` | Merino Wool Crew Sweater | Active discount window right now |
| 8 | `ELC-0015` | Portable Bluetooth Speaker Waterproof | Listed (`is_available = true`) but zero sellable stock across every variation |
| 9 | `CLM-0011` | Wool Blend Overcoat | Exactly one unit available (one variation, L-GRY) |
| 10 | `HND-0007` | Tape Measure 8m Locking | `min_order_quantity` above 1 (3) |
| 11 | `CLM-0003` | Cotton Tank Top Athletic Fit | Single variation, no discount, no distinguishing feature — the baseline every other row contrasts against |
| 12 | `CLM-0007` | Chino Trousers Regular Fit | Zero reviews at all — contrast to case 6 |
| 13 | `BTY-0001` | Hydrating Face Moisturiser SPF 30 | No images at all — see "The one manufactured case," below |

## Two picks were wrong on the first pass

**Case 4 originally pointed at `CLM-0022`, which does not exist.** A
leftover placeholder from an earlier draft of this list, never updated to
the verified SKU (`WRK-0004`) before the seeder first ran. The seeder's own
"skip a SKU that does not match, do not fail the run" design meant this
produced 12/13 matched rather than an error — caught only by checking the
seeder's own reported count against the expected 13, not by reading the
code.

**Case 8 originally pointed at `CLM-0016`, which is the wrong kind of
"unavailable."** `CLM-0016` has `is_available = 0` — deactivated, hidden by
`ProductList::applyFilters()`'s own gate and by the demo-mode query's
identical `is_available = true` clause, so it never appeared in the demo
sequence at all despite having `demo_case_order` set. "Out of stock" and
"deactivated" are two different states this schema already distinguishes
(`products.is_available` vs. `inventories.current_quantity -
reserved_quantity`), and the first SQL query used to find a candidate for
this case only checked the inventory side. `ELC-0015` — listed, genuinely
zero stock — is the corrected pick.

Both are the reason `DemoShowcaseOrderSeeder::run()` resets every product's
`demo_case_order`/`demo_case_label` to `null` before reassigning, rather
than only ever adding: a SKU removed from `CASES` (exactly what happened to
both of these) would otherwise keep its stale assignment forever, since a
plain `UPDATE ... WHERE sku = ?` per case is additive by nature and never
notices a row that fell out of the list.

## The one manufactured case

Every product in the seeded catalogue has at least one real photo —
`demo:fetch-images` was run against all 182 `product_images` rows this
session, specifically so nothing would be missing one. "No images" does not
exist anywhere in the data on its own, so this seeder is the only case that
does more than label a product: `DemoShowcaseOrderSeeder::stripImages()`
deletes every `product_images` row for `BTY-0001` after the labelling pass.
The pivot rows in `product_image_product_variation` cascade with it
(`cascadeOnDelete()` on `product_image_id`) — nothing separate needed for
the variation side. `BTY-0001` was picked because it is otherwise
unremarkable (one variation, no discount, not used by another case), so the
empty-gallery fallback (`public/images/default-product.png`, wired into
both `product-list.blade.php` and `product-details.blade.php`) is the only
thing this case actually tests.

## Adding, removing, or re-picking a case

1. Verify the SKU exists and actually exhibits the case with a direct query
   against the real database — not a query that looks like it should work.
   Both mistakes above happened exactly by skipping this step.
2. Check it is not already used by another case in `CASES`.
3. Update `DemoShowcaseOrderSeeder::CASES` and re-run it —
   `php artisan db:seed --force --class="Database\Seeders\Demo\DemoShowcaseOrderSeeder"`.
   The reset-first design means a re-run always produces exactly the current
   list, nothing stale left over from a previous version.
4. Confirm the count: the seeder logs `N/13 cases matched` — anything less
   means a SKU in `CASES` does not exist or was typed wrong, the same way
   case 4's placeholder was caught.
5. If the total case count itself changes, `ProductList::CASES_COUNT` (the
   demo-mode page size, so all cases render on one page) has to change with
   it — the two are not otherwise connected and Larastan cannot catch a
   mismatch between them.
