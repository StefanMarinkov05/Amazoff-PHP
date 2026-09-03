---
name: block-coauthor-trailer
enabled: true
event: bash
action: block
conditions:
  - field: command
    operator: regex_match
    pattern: (?i)co-authored-by
---

🚫 **`Co-Authored-By` trailer is forbidden on this repo**

CLAUDE.md, Working style:

> Commit messages are written by hand and reviewed as part of the project's
> implementation standards — **never add a `Co-Authored-By` trailer, on this
> repo or any fork/branch of it.**

This rule exists because the trailer is injected as a per-turn default from
outside the repo, and the project rule has to win every time — not just when
someone remembers.

Rewrite the commit message without the trailer.
