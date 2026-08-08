# Documentation

This is where all the project docs live. We're following
[Diátaxis](https://diataxis.fr/) for the structure, plus two extra folders
for decisions and release history.

## [Tutorials](tutorials/)

For someone brand new to the codebase. Walk them through it step by step,
top to bottom — this isn't a reference, it's a guided first lap.

## [How-to](how-to/)

Short recipes for a specific job: run the seeder, add a courier, rotate a
Stripe key. Assumes the reader already knows the basics.

## [Reference](reference/)

Just the facts — schema, state diagrams, API shapes. No opinions, no "why,"
just what's true right now.

## [Explanation](explanation/)

How things fit together as they are today. Pulls several decisions into one
picture, points at ADRs by number instead of repeating their reasoning, and
gets updated whenever the system changes.

## [ADR](adr/)

One decision, one file, frozen the moment it was made. Once accepted, it
doesn't get edited — if we change our mind later, we write a new ADR and
mark the old one `Superseded by ADR-XXXX`.

## [Changelog](changelog/)

What actually shipped, in order: added, changed, fixed, removed. One entry
per release.

---

**Explanation vs. ADR**, since the two are easy to mix up: an ADR argues for
one choice at one point in time ("Filament or hand-rolled CRUD? Filament —
here's why, here's what we gave up"). Explanation just describes how things
work right now, with nothing left to defend. Quick test: if the doc is
making a case, it's an ADR; if it's describing a result, it's explanation.

**Not decided yet** — the original draft described "common errors and how
they're fixed," which is really a troubleshooting guide, not a changelog.
Early planning had a `docs/troubleshooting/` folder for this;
it doesn't exist here yet.
