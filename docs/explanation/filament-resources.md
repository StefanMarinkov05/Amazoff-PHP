# How a Filament resource is put together

What each file in a resource folder does, and where the Actions plug in. The
worked example is `app/Filament/Resources/Products`, which is the only resource
that currently routes writes through Actions.

Why writes go through Actions at all is ADR-0007; what each Action does is
`reference/actions.md`.

## The folder

```
Products/
├── ProductResource.php              the registration: model, icon, wiring
├── Pages/
│   ├── ListProducts.php             the index screen
│   ├── CreateProduct.php            the create screen
│   └── EditProduct.php              the edit screen
├── Schemas/ProductForm.php          the fields, shared by create and edit
├── Tables/ProductsTable.php         the columns, filters, row actions
└── RelationManagers/                one per child collection
    ├── ProductVariationsRelationManager.php
    ├── ProductImagesRelationManager.php
    └── ProductSpecificationsRelationManager.php
```

`ProductResource` itself holds almost no behaviour. It names the model, points
`form()` at `ProductForm` and `table()` at `ProductsTable`, lists the relation
managers in `getRelations()`, and maps URLs to pages in `getPages()`. The split
into `Schemas/` and `Tables/` is Filament's generator convention: a resource
with the form and table inline grows to several hundred lines, and the two are
edited for unrelated reasons.

It also overrides `getRecordRouteBindingEloquentQuery()` to drop the
`SoftDeletingScope`, which is what lets `/admin/products/5/edit` open a
soft-deleted product so it can be restored. That override is the reason
several Actions re-read a record instead of trusting `trashed()` on the model
they were handed.

## Pages, and the `handle*` methods

A page is a Livewire component. `CreateRecord` and `EditRecord` are Filament
base classes that already know how to render a form, validate it, save, and
redirect. Overriding a `handle*` method replaces **only the persistence step**,
leaving the rest of that pipeline intact:

| Method | On | Replaces |
|---|---|---|
| `handleRecordCreation(array $data)` | `CreateRecord` | `Model::create($data)` |
| `handleRecordUpdate(Model $record, array $data)` | `EditRecord` | `$record->update($data)` |

They are not required — Filament works without them, which is exactly the
problem they solve here. The default writes one table, and a product spans
three, so without the override the panel produced variations with no
`inventories` row.

`$data` arrives already validated by the form schema and already stripped of
components that were hidden, which is why the create-only variations repeater
is simply absent from the edit payload rather than present and empty.

## `getHeaderActions()` and the other action slots

An **action** in Filament is a button plus what it does: an optional
confirmation, an optional modal form, and a callback. Where a button appears is
decided by which method returns it.

| Slot | Where it renders |
|---|---|
| `getHeaderActions()` on a page | top-right of that screen — Create on the list page, Delete/Restore on the edit page |
| `->headerActions()` on a table | above the table, acting on no particular row — "New variation" |
| `->recordActions()` on a table | on each row — Edit, Delete, "Make main" |
| `->toolbarActions()` on a table | on the current selection — bulk operations |

Built-in actions (`DeleteAction`, `ForceDeleteAction`, `CreateAction`) have a
default behaviour that writes Eloquent directly. `->using(...)` replaces that
behaviour while keeping the button, its confirmation modal, and its policy
check — which is how a delete button ends up calling `DeleteProduct` instead
of `$record->delete()`.

`Action::make('setMain')` is the other case: a button with no built-in meaning
at all, whose `->action()` is the whole implementation.

**Bulk actions are deliberately missing** from the variations and images
relation managers. A bulk action receives a collection and writes it directly;
routing it through an Action would need a batch path that re-checks the
invariant per row, and no such path exists. Removing the button is honest,
where leaving it is a bypass of everything the wiring establishes. Restore
bulk actions stay, because restoring cannot break the invariant.

## Relation managers

A relation manager is a table for one `HasMany`/`BelongsToMany` on the record
being edited. It is its own Livewire component with its own form, table, and
actions, and it only appears on the edit page — which is why anything a product
*must* have at creation cannot live in one, and the first variation is
collected by a repeater on the create form instead.

`$this->getOwnerRecord()` is the parent, which is how an Action that takes a
`Product` gets one.

## Where domain failures go

Actions fail with domain exceptions (ADR-0007). Left uncaught in a panel those
render as a 500, which is the wrong answer for a refusal an administrator can
act on — "this variation has stock history" is information, not a crash.

`App\Filament\Concerns\ReportsDomainFailures` wraps a call, turns an
`App\Exceptions\*` into a persistent notification, and halts the action.
Anything else is re-thrown: a `QueryException` is a defect, and swallowing one
into a toast would hide exactly the failures that should stay loud.

## Which children need an Action, and which do not

ADR-0007's threshold is a write spanning more than 1 table, or an invariant
the schema cannot express. Applied to the product's three children:

| Relation manager | Routes through Actions | Why |
|---|---|---|
| Variations | yes | a variation needs an `inventories` row; removal must not strand held stock |
| Images | yes | exactly one `is_main` per product; removal cascades out of every variation gallery |
| Specifications | **no** | 1 table, no invariant, no second writer — default CRUD, per CLAUDE.md's rule that wrapping a single-table save in an Action buys nothing |

Specifications being plain CRUD is a decision, not an omission. It is the same
call as `Brand`, `Tag` and the other lookup tables.

## Image uploads

`FileUpload` on the images relation manager writes to the `public` disk under
`product-images/`, both named as constants on `ProductImage` so the upload
field and `RemoveProductImage` cannot drift onto different disks.

`storage/app/public` is git-ignored by Laravel's own `.gitignore`, and
`php artisan storage:link` exposes it at `/storage`. Production replaces the
disk with object storage; nothing outside `ProductImage::DISK` needs to change.

`RemoveProductImage` deletes the file **after** the transaction commits. A
rollback would otherwise leave the row intact and the file gone, which is the
one combination nothing can repair.

## Static, checked-in assets

Not every image is user-uploaded. `public/` holds files that ship with the app
and are never written by an Action or a Filament form. Laravel serves anything
under `public/` directly; nothing runs `storage:link` for these, and nothing
purges them.

| File | Path | Used by |
|---|---|---|
| Logo | `public/images/logo.png` | Filament panel branding (`brandLogo()`) and, once built, the storefront header |
| Favicon | `public/favicon.ico` | The browser tab icon, at the conventional root path — overwrites Laravel's own default `favicon.ico`, not placed under `images/` |
| Default product image | `public/images/default-product.png` | `ResolveVariationImage::urlOrDefault()` — see below |

The favicon's path is not a free choice the way the other two are: browsers
request `/favicon.ico` at the domain root without being told to, so anywhere
else requires an explicit `<link rel="icon">` in a layout — and no non-`welcome`
layout exists yet for one to live in. Placing it at the root is what makes it
work with zero additional code.

None of these three exist in the repository yet; the paths are reserved so
code can reference them ahead of the files landing.

## The variation gallery modal

`ProductVariationsRelationManager`'s "Images" row action opens a `Repeater`
rather than the plain multi-select an earlier draft used — `reference/write-
rules/product-variation-images.md` has the write behaviour;
this is the admin surface built on top of it, ADR-0013's gallery-as-a-set
Action.

Each repeater row is two form components, not a table column: a `ViewField`
rendering `resources/views/filament/forms/components/variation-image-
thumbnail.blade.php`, and a `Select` scoped to the variation's own product's
images. The thumbnail is a live preview, not a stored value — it reads
whatever the row's `Select` currently holds and re-renders on change via
`->live()`, so picking a different photo swaps the thumbnail before the
gallery is saved. Both must set `->live()` for this to work: the `Select`
to *emit* the change, the `ViewField` to *react* to it.

Array order in the Repeater becomes gallery `position` — dragging a row
(`->reorderableWithDragAndDrop()`) is the entire reorder mechanism; there is
no separate move-up/move-down control and no `orderColumn` binding to the
database, because the modal always submits the whole set through
`SetVariationImages` in one call rather than writing incrementally.

`fillForm` matters more here than in a typical Filament form: it has to hand
the Repeater the gallery already in `position` order, or opening the modal
and saving with no changes would silently rewrite every position to
whatever order Eloquent happened to load the pivot rows in.

The thumbnail's fallback — when a row has no `image_id` selected yet, or (in
`urlOrDefault`, used wherever a resolved image is displayed rather than
edited) when a variation has no gallery and its product has no main image
either — is the static asset table above, not a broken `<img>` tag.
