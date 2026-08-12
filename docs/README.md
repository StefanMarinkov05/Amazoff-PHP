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

**Troubleshooting** is the "common errors and how they're fixed" material from
the original draft, which was never a changelog: a changelog entry says a bug is
gone and is filed under the date of the fix, while a troubleshooting entry is
found by the symptom someone is looking at right now.

It lives at `how-to/troubleshooting.md` rather than in the `docs/troubleshooting/`
folder early planning had. Someone with a symptom is someone with a specific job,
which is what how-to is for, and one file is searchable in a way a folder of
one-problem files is not. The folder becomes worth it if the guide outgrows a
single sitting.
