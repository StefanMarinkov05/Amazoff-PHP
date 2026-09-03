# How to set up Claude Code for this repo

The repo ships a shared plugin list and four `hookify` rules that enforce
CLAUDE.md's own invariants mechanically. Installing the plugin is one
command, and it's the only Claude Code setup this repo asks of you.

None of it is required to work on the project — Claude Code works fine
without it. What you'd be skipping is a hook catching a mistake before it
reaches a commit, in place of catching it in review.

## Install hookify

The plugin list is committed in `.claude/settings.json`, so Claude Code
prompts to enable it on first run in this repo. If it does not, or the
prompt was dismissed:

```bash
claude plugin marketplace add anthropics/claude-plugins-official
claude plugin install hookify@claude-plugins-official
```

Restart the session afterwards — plugin installs need it, though editing a
hookify *rule* does not.

If `claude` is not on your PATH and you use the VS Code extension, the
binary is bundled inside it:

```bash
ln -s ~/.vscode/extensions/anthropic.claude-code-*/resources/native-binary/claude ~/.local/bin/claude
```

`set-up-stripe.md` has the longer version of that troubleshooting, including
the case where the extension has several versions installed.

## What it's for

`hookify` runs the four rules in `.claude/hookify.*.local.md`. Without the
plugin those files are inert markdown — nothing reads them, and no warning
says so.

## The hookify rules

Four, all committed, all local-only in effect. They restate rules CLAUDE.md
already states — the point is that a hook cannot forget.

| Rule | Event | Action | Catches |
|---|---|---|---|
| `require-ci-shard-entry` | file | warn | A test file edit, reminding that `ci.yml`'s shard list is hand-maintained and an unlisted test runs nowhere |
| `block-coauthor-trailer` | bash | **block** | `Co-Authored-By` reaching a commit message |
| `block-env-file-reads` | bash | **block** | `cat`/`grep`/`cp` against `.env`, while allowing `.env.example` |
| `warn-editing-merged-migration` | file | warn | A migration edit, with the `git log` check that tells merged from unmerged |

Two are `block` rather than `warn` deliberately. The `Co-Authored-By`
instruction arrives from outside the repo on most turns, so the project rule
has to win mechanically rather than by anyone remembering it; and
`secrets-and-env.md`'s `.env` policy is the one rule where a single slip
puts live credentials in a transcript that outlives the session.

Rules take effect on the next tool use — no restart. To disable one, set
`enabled: false` in its frontmatter; to see them all, `/hookify:list`.

### If a rule fires wrongly

Tighten the pattern rather than deleting the rule, and test the pattern
before trusting it. The `.env` rule shipped with a bug on its first draft —
a greedy `[^|;&]*` swallowed the space, so plain `cat .env`, the single most
likely command, did not match while `head -5 .env` did. It now passes 17
block/allow cases including `.env.example`, `.env.testing`, and
`.environment`.

That is the same rule this project applies to its own tests: a guard that
has never been observed failing proves nothing. Write the failing case
first.

## What is deliberately not shared

`.claude/skills/` and `.claude/settings.local.json` stay gitignored.

The first is a set of symlinks into a personal skills library that does not
exist on another machine; the second is per-machine MCP configuration. Only
`settings.json` and the hookify rules are committed, and `.gitignore` does
this by excluding `.claude/*` and re-including those two patterns — ignoring
the directory itself would stop git descending into it at all, and the
negations would never be reached.

## Other plugins

`semgrep` and `playwright` are evaluated in `misc/plugin-evaluation.md` and
are **not** part of this repo's shared setup — one wanted a cloud login and
was disabled; the other is browser-automation tooling used ad hoc for §37
#19 verification, not something every contributor needs installed. If you
want either, that file has the reasoning and the install path.
