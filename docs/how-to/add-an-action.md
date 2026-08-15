# How to add an Action

The recipe. ADR-0007 holds the reasoning for every rule below and is frozen —
read it there rather than expecting this page to restate the argument.
`reference/actions.md` lists what already exists.

## 1. Check that it needs to be an Action

An Action is required where a rule exists: the write spans more than one
table, or it enforces an invariant the schema cannot express. A single-table
save has neither, so a lookup table keeps Filament's default CRUD.

If the answer is no, stop. The cost of a wrong yes is a class; the cost of a
wrong no is two developers implementing the same rule differently.

## 2. Put it in an area folder

```
app/Actions/{Area}/{Verb}{Noun}.php
```

The area is the aggregate being written, not the caller. Group by what the
Action touches so that a second caller does not move the file.

## 3. Write the shape

```php
final class ReserveStock
{
    public function __construct(private readonly RecordInventoryMovement $recordMovement) {}

    public function handle(ProductVariation $variation, int $quantity, ?User $actor = null): Inventory
    {
        // ...
    }
}
```

One public `handle()`. Parameters are models and scalars — never a `Request`,
which is what lets a Filament page, a Livewire component, a seeder, and a test
all call the same class. Input shape is a Form Request's job; the Action
validates domain state.

## 4. Decide on the actor

`?User $actor = null`, **last**. Null means the system — a webhook, the
scheduler, a queued job — and skips the policy check. A non-null actor is
authorized before anything is written:

```php
if ($actor !== null) {
    Gate::forUser($actor)->authorize('create', Product::class);
}
```

Omit the parameter entirely if the Action has no non-human caller. Last rather
than first, so that leaving it out looks like an omission in review.

When one Action calls another, pass the actor on. Passing `null` from a
caller that has one is the single place in this codebase where forgetting a
parameter weakens a security check rather than raising an error.

## 5. Decide on the transaction

Open `DB::transaction` when the Action writes more than one row. Do not open
one for a single statement — it changes no outcome, and no test can tell it
apart from its absence.

Nesting is safe: Laravel implements it with savepoints, so an inner Action
joins the outer boundary and the outermost commits. An Action never needs to
know whether it is the outermost caller.

For contested state — a row read to decide whether another request may
proceed — the transaction is not enough. Add `lockForUpdate()` before the
read, and prefer `increment()`/`decrement()` over PHP arithmetic. See
`explanation/concurrency-and-locking.md` first.

## 6. Fail with a domain exception

```php
throw new InsufficientStockException($variation, $requested, $available);
```

Never a `false` or a `null` return — an exception cannot be ignored at a call
site by accident, and the message the customer sees stays the caller's
decision. Use `InvalidArgumentException` where the condition is a caller bug
rather than something the customer could act on.

Put the exception in `app/Exceptions` and give it the data a caller needs to
build its own message.

## 7. Call it from the panel

Where an Action is required, the Filament resource calls it rather than
letting the page write the model:

```php
protected function handleRecordCreation(array $data): Model
{
    return app(CreateProduct::class)->handle($data, $variations, auth()->user());
}
```

`handleRecordUpdate(Model $record, array $data)` is the edit-page equivalent,
and `CreateAction::make()->using(...)` is the relation-manager one.

No resource does this yet. `ProductResource` is the first that needs to.

## 8. Test it

`tests/Feature/Actions/{Area}/`. Construct through `app()`, call `handle()`,
assert. No HTTP.

Cover whichever of the six obligations apply — authorization, ownership,
server-side computation, idempotency, contested state, input shape. The
obligations and the Action they each attach to are mapped in the working plan;
the mechanics are in `run-the-tests.md`.

## 9. Prove each test can fail

Delete the mechanism the test guards, run it, confirm it goes red, restore it.

This is not optional and it is not a formality. Three concurrency tests in the
inventory slice passed with `lockForUpdate()` removed. One authorization test
in the catalogue slice passed with its `Gate` check removed, because the actor
lacked a second permission and a nested Action was raising the exception the
test attributed to the outer one. In both cases the test looked correct and
asserted the right-sounding thing.

A test that has never been observed failing is not evidence.

## 10. Update the reference

Add the Action to `reference/actions.md` — its area, what it writes, whether it
takes an actor, what it throws, whether it opens a transaction, and who calls
it. That table is how the next person decides whether the rule they need
already exists.
