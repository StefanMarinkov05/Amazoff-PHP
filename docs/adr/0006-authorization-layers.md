# ADR-0006: Where authorization lives

Status: Accepted
Date: 2026-08-13 · Deciders: Stefan Marinkov

## Context

§3 names five roles, §3.5 requires roles and permissions editable without a
deploy, and §37 criterion 18 is graded on roles *not* reaching what they should
not. §34 adds that unauthorized users cannot reach administrative pages and
that content editors cannot reach payment data.

"May this person do this" is four questions wearing one coat:

1. **Is this person staff at all?** A customer has no business at `/admin`,
   whatever else is true about them.
2. **May their role do this kind of thing?** Editing articles, changing order
   statuses, refunding a payment.
3. **May they do it to *this record*?** A customer reads their own order and
   nobody else's; §34 calls this preventing unauthorized resource access.
4. **Is this person exempt from the question?** §3.5 gives the administrator
   everything, including abilities added after the role was created.

Answering them in one place produces the two failure modes this project has to
avoid. Checking roles inside policies makes authorization go stale the moment
an administrator edits a role, which is precisely what §3.5 asks to be
possible. Checking only at the panel produces "a hidden button is not
security" — the state the codebase was in before this ADR, where seven Filament
resources were reachable by any staff account because `canAccessPanel()` was
the only check that existed.

The roles themselves are data rather than an enum, decided in ADR-0001. That
decision is what forces the rest: if the role→permission mapping changes at
runtime, nothing compiled against role names can be trusted.

## Decision

Each question is answered in exactly one place.

### 1. Panel entry is a role check on the User model

`User::canAccessPanel()` asks whether someone is staff, using an allow-list of
role names (`User::STAFF_ROLES`) and their `is_active` flag. It is not a policy
and not a permission: panel access is not per-model authorization, it is
"does this person work here".

An allow-list rather than a deny-list, so a role added later reaches the panel
only when it is named, rather than by default. `is_active` is checked here
rather than at login because a session outlives the row it authenticated
against — deactivating an employee has to end their access on the next
request.

### 2. What a role may do is a permission row

Permissions are `spatie/laravel-permission` rows, named `{ability}_{resource}`
where the ability half is the Laravel policy method that checks it —
`viewAny_product`, `updateStatus_order`, `publish_article`. The ability is
camelCase and never contains an underscore, so the first underscore splits the
two halves unambiguously even for `viewAny_product_review`.

The naming is not cosmetic. It keeps every policy method to one line, and it
keeps permission names out of the namespace of policy method names — a
permission called plain `update` would collide with `Policy::update()` when
resolved through the gate.

Users hold roles; roles hold permissions. Spatie's `model_has_permissions`
table, which grants a permission to one user directly, stays unused: a grant
that appears in no role definition is invisible to anyone auditing the roles
UI, and `RoleSeeder` would never revoke it because it syncs roles rather than
users.

The full catalogue is seeded even for resources with no admin surface yet.
§3.3 and §3.4 describe what a role may do, not what happens to be built, and
content_editor's deny-list is only testable if the permissions it excludes
exist.

### 3. Whether this record is theirs is a policy

Policies check permissions, never role names, and add per-record conditions
where ownership matters:

```php
return $user->can('update_article')
    && $article->author_id === $user->id;
```

The first half is the role-wide question, the second is the per-record one.
Lookup tables have no owner, so their policies are currently the first half
only.

Filament resolves policies by convention and uses `viewAny` for both the
navigation item and the route, so hiding the link and blocking the page are
one check rather than two that can disagree. A resource whose model has no
policy is reachable by anyone who passes `canAccessPanel()` — authorization
fails *open*, silently, which is why the test suite asserts policy existence
separately from policy behaviour.

Because the failure is silent, policies are written for every resource the
catalogue names rather than for every resource that exists. A policy written
after the Filament resource has a window in which the resource is live and
ungated; a policy written before it has none.

Two abilities are refused outright rather than being made permissions:
creating an order and creating a payment. An order exists because a customer
completed checkout and a payment because Stripe reported one, so a panel
form for either would be a way to author history rather than record it.
`Gate::before` does not reach these, because the policy is what returns
false and the administrator bypass never consults a permission that does not
exist.

One policy is registered by hand. `Spatie\Permission\Models\Role` lives in
the package's namespace, so convention finds nothing for it — and it is the
model that decides what every other role may do.

### 4. The administrator exemption is one Gate::before

`AppServiceProvider` registers a single callback returning `true` for the
administrator role and `null` otherwise. `null` rather than `false` is
load-bearing: `false` would deny every check for every other user before their
policy ran.

The alternative — attaching all ~100 permissions to the role — was rejected
because the catalogue grows at runtime under §3.5, so the role would drift out
of step with it permanently and silently.

### The roles screen edits permissions, not roles

§3.5 is only satisfied if someone can exercise it, so a Filament resource over
spatie's `Role` model provides the checkbox grid. It offers edit and nothing
else.

Creating a role in the UI would produce a role that grants no panel access,
because `canAccessPanel()` gates on `User::STAFF_ROLES` — a PHP constant. The
control would appear to work while doing nothing, which is worse than its
absence. Renaming is refused for the same reason in reverse: the name is what
`RoleSeeder` and `STAFF_ROLES` match on, so a rename silently strips access
from everyone holding the role. Adding a staff role stays a code change.

This leaves two paths to the same outcome that are deliberately not
equivalent. The panel is immediate and local; `RoleSeeder` is authoritative
and survives `migrate:fresh --seed`, because it syncs. A panel change that
should be permanent has to be made in the seeder too, which
`how-to/edit-a-role.md` states plainly.

## Consequences

+ A permission revoked through the panel takes effect on the next request,
  with no deploy, which is what §3.5 asks for.
+ Adding a resource means adding a policy and permissions, not editing an
  authorization service that every resource shares.
+ §37 criterion 18 is testable as a matrix rather than by inspection.
+ Two independent checks stand between a customer and admin data — the panel
  gate and the policy — so neither is individually load-bearing.

− **A policy can no longer deny an administrator anything.** `Gate::before`
  short-circuits before the policy runs, so a rule like "nobody may delete a
  paid order" cannot live in a policy. It belongs in the Action as a domain
  invariant, which is where ADR-0004 already puts the question of whether a
  transition is legal at all. This is the main cost of the decision and the
  one most likely to surprise someone.
− Re-seeding resets roles to the spec'd lists. `RoleSeeder` uses
  `syncPermissions()` so that a permission removed from the code is actually
  revoked, which necessarily discards runtime edits an administrator made
  through the panel. `DatabaseSeeder` is therefore a first-boot and
  development operation, not something to run against a populated production
  database.
− A missing policy fails open rather than closed. Laravel has no way to
  require one, so the guarantee is a test rather than a mechanism.
− Permission rows exist for resources nothing checks yet. They are inert, but
  they will appear in a roles UI before the features they describe.

## Alternatives rejected

- **Roles as a backed enum, checked directly.** Consistent with every other
  fixed value set in the codebase (`App\Enums`, ADR-0001) and simpler to read.
  Rejected because §3.5 requires runtime editing: an enum is compiled, so the
  administrator would need a deploy to change what a content editor may do.
- **Checking roles inside policies** (`$user->hasRole('content_editor')`).
  Shorter, and needs no permission table. Rejected because it is wrong the
  moment an administrator edits a role — the check would still return true for
  a role that no longer holds the permission. It reintroduces exactly the
  staleness the permission table exists to prevent.
- **A single authorization service class** consulted by every controller and
  resource. Centralises the rules, but Filament calls policies by convention;
  bypassing that means re-implementing resource authorization by hand in every
  resource, which is more code and more places to forget.
- **Per-user permission grants** via `model_has_permissions`. Useful for
  one-off exceptions. Rejected because such a grant appears in no role, so
  auditing the roles UI would give a confidently wrong answer about that user.
- **Generating policies from the permission catalogue** at runtime, so a
  resource cannot be missed. Removes the fails-open gap, at the cost of a
  policy layer nobody can read or step through in a debugger, and it makes the
  per-record half of question 3 awkward to express. The repetition across
  policies is real but each file is legible on its own, which matters more for
  a codebase two people are learning on.
