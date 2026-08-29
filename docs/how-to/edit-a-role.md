# How to change what a role may do

§3.5 requires this without a deploy. The roles screen is what makes that true.

For the list of permissions and what each role currently holds, see
`reference/permissions.md`. For why the arrangement is this shape, ADR-0006.

## Through the panel

1. Sign in as an administrator and open **Roles & permissions**.
2. Edit `content_editor` or `warehouse_employee`.
3. Tick or untick boxes. Permissions are grouped by area — Catalogue,
   Content, Operations, Administration — with one checkbox list per resource.
   Only Catalogue is expanded by default.
4. Save.

The change is live on the next request. No deploy, no re-seed, no cache clear
by hand — `EditRole::afterSave()` clears the permission cache.

Editing `administrator` has no effect on what it may do. It holds no
permissions, and a `Gate::before` callback grants it every ability regardless
of the pivot table, so ticked boxes are saved and then never consulted. The
edit form shows a note to that effect above the checkboxes.

Nothing prevents the save — the boxes are not disabled and there is no
validation rule. The note is the only thing standing between an administrator
and a pointless edit.

## What the screen deliberately does not do

**Create or delete roles.** `canAccessPanel()` gates the panel on
`User::STAFF_ROLES`, a PHP constant. A role created in the UI would grant no
panel access until someone edited that constant and deployed — a control that
appears to work while doing nothing. Adding a fourth staff role is a code
change: add it to `STAFF_ROLES`, add its permission list to `RoleSeeder`, and
deploy.

**Rename a role.** The name is what `RoleSeeder` and `STAFF_ROLES` match on, so
renaming would silently strip panel access from everyone holding it. The field
is shown disabled.

**Assign a role to a user.** That belongs on the user record, which has no
Filament resource yet.

## Through a seeder instead

Use this when the change should apply to every environment and survive a fresh
database — a permission the role should always have had, rather than a local
adjustment.

Edit the list in `database/seeders/System/RoleSeeder.php`, then:

```bash
docker compose exec app php artisan db:seed --class="Database\Seeders\System\RoleSeeder"
```

`RoleSeeder` uses `syncPermissions()`, so the role is reset to exactly the
list in the file. **This discards panel edits.** A permission granted through
the UI and not added to the seeder is revoked the next time the seeder runs.

That is deliberate — it is what makes a permission removed from the code
actually disappear — but it means the two paths are not equivalent:

| | Panel | Seeder |
|---|---|---|
| Takes effect | Next request | Next seed run |
| Survives `migrate:fresh --seed` | No | Yes |
| Applies to other environments | No | Yes |
| Needs a deploy | No | Yes |

If a panel change should be permanent, make the same change in `RoleSeeder`.

## Adding a new permission

1. Add the resource or ability to `App\Support\PermissionCatalogue`. Both the
   seeder and the roles UI read from there, so one edit covers both.
2. Add the matching method to the policy, if it is a new ability rather than a
   new resource.
3. Grant it to whichever roles should hold it in `RoleSeeder`.
4. Re-seed: `php artisan db:seed --class="Database\Seeders\System\PermissionSeeder"`,
   then `--class="Database\Seeders\System\RoleSeeder"`.

A permission with no policy method behind it is inert — nothing checks it. A
policy method with no permission is worse: `$user->can('typo_product')` is
`false` for everyone except the administrator, and nothing reports the typo.
`tests/Feature/RolePermissionTest.php` asserts every catalogue model resolves
a policy, which catches the second case at the resource level.

## Verifying a change

```bash
docker compose exec app php artisan tinker
```

```php
$u = App\Models\User::where('email', 'editor@example.com')->first();
$u->can('viewAny_brand');          // the ability in question
$u->getRoleNames();                // which roles they hold
$u->getPermissionsViaRoles()->pluck('name');   // everything the roles give
$u->getDirectPermissions()->pluck('name');     // should be empty — see below
```

`getDirectPermissions()` should always be empty. Spatie supports granting a
permission to one user directly, and this project does not use it: such a
grant appears in no role, so the roles screen would give a confidently wrong
answer about that user. If it ever returns something, that is the bug.
