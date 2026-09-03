---
name: warn-editing-merged-migration
enabled: true
event: file
action: warn
conditions:
  - field: file_path
    operator: regex_match
    pattern: online-store/database/migrations/.*\.php$
---

⚠️ **Migrations are append-only after the schema freeze**

CLAUDE.md, Working style:

> Migrations are append-only after the schema freeze. **Never edit a merged
> migration; always add a new one.**

Editing a migration that is already on `main` means every environment that
has run it keeps the old schema while the file claims the new one —
`migrate:fresh` locally then diverges silently from anything already
migrated.

**If this file is already merged**, stop and write a new migration instead:

```bash
docker compose exec app php artisan make:migration <describe_the_change>
```

Check first:

```bash
git log --oneline main -- <this file>
```

No output → the migration is new and unmerged, and editing it is fine.
