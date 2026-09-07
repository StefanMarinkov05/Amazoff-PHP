---
name: require-ci-shard-entry
enabled: true
event: file
action: warn
conditions:
  - field: file_path
    operator: regex_match
    pattern: src/tests/(Unit|Feature|Concurrency)/.*Test\.php$
---

⚠️ **New or edited test file — check the CI shard list**

`.github/workflows/ci.yml` uses a **hand-maintained file list**, not
auto-discovery. A test file that is not listed in a shard **runs nowhere** —
CI goes green while the test never executes.

If this is a **new** test file, add it in the same change:

- `tests/Unit` / `tests/Feature` → **shard 2** by default
- shard 1 only if it carries `RolePermissionTest`'s per-test triple-reseed cost
- `tests/Concurrency` → the **lightest concurrency shard** by default

`docs/how-to/use-ci.md` has the placement rule. CLAUDE.md states this as a
same-change requirement, not a follow-up.
