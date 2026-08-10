# How to choose a model and effort level

Which Claude model and reasoning setting to use for which kind of work on this
project.

Model names, prices, and available settings change; the figures below were
current at the time of writing and are the kind of fact that goes stale
quietly. `/model` in Claude Code lists what is actually available.

## Three levers, not one

**Model** — capability tier. **Effort** — how much the model reasons and how
many steps it takes before answering. **Fast mode** — same model, faster output,
higher price.

The common mistake is reaching for the model lever first. Effort moves quality
more than a model downgrade saves money on most of this project's work, and a
lower effort level on a strong model usually beats a weaker model at high
effort.

## Models

| Model | Input / output per Mtok | Context | Use for |
|---|---|---|---|
| Fable 5 | $10 / $50 | 1M | The hardest long-horizon work. Rarely justified here |
| Opus 5 | $5 / $25 | 1M | Default. Business logic, architecture, review |
| Sonnet 5 | $3 / $15 | 1M | High-volume mechanical work where quality still matters |
| Haiku 4.5 | $1 / $5 | 200K | Bulk extraction and formatting only |

Thinking is on by default on Opus 5. Haiku 4.5 does not accept an effort
setting at all — asking for one is an error, not a silent downgrade.

## Effort

Five levels: `low`, `medium`, `high`, `xhigh`, `max`. The default is `high`.

`xhigh` is the recommended starting point for coding and agentic work on Opus 5
and Sonnet 5, and `high` for everything else. From there the useful move is
downward: `low` and `medium` on Opus 5 are stronger than their names suggest,
and on routine work often match what a previous generation produced at its
ceiling.

Higher effort is not uniformly better. It costs latency and tokens, and on
simple tasks it produces over-exploration — more tool calls, more verification,
wider scope than asked for. `max` earns its place only where correctness
outweighs both cost and time.

## What to use for what here

| Work | Model | Effort |
|---|---|---|
| Business logic — `TransitionOrderStatus`, stock reservation, coupon redemption, VAT arithmetic | Opus 5 | `xhigh` |
| Stripe webhook handling, idempotency, signature verification | Opus 5 | `xhigh` |
| Courier gateway and the two implementations behind it | Opus 5 | `xhigh` |
| Policies and authorization | Opus 5 | `xhigh` |
| A vertical slice end to end (migration → model → policy → resource → UI) | Opus 5 | `high` |
| Debugging a failing test with a stack trace | Opus 5 | `high` |
| Code review of a branch before a PR | Opus 5 | `xhigh` |
| ADRs, explanation docs, specification analysis | Opus 5 | `high` |
| Filament resources over an existing model | Sonnet 5 | `high` |
| Blade views and Livewire components against a settled design | Sonnet 5 | `high` |
| Enum, cast, and factory sweeps across many files | Sonnet 5 | `medium` |
| Blueprint regeneration and the corrections that follow it | Sonnet 5 | `medium` |
| Demo fixture prose, generated in batches | Sonnet 5 | `medium` |
| Bulk reformatting, extraction into a fixed shape | Haiku 4.5 | n/a |

The split follows one line: **anything where being wrong is expensive and not
obviously wrong gets Opus 5 at `xhigh`.** A miscalculated VAT total or a webhook
that processes an event twice does not announce itself in review; a Blade
template that renders badly does.

`security-baseline` and the §37 acceptance criteria mark the same boundary from
the other side — the criteria that carry money, stock, or authorization are the
ones that justify the cost.

## Fast mode

`/fast` runs Opus 5 with faster output at a higher price. It is not a downgrade
to a smaller model.

Worth it when latency is the constraint and the work is already well specified —
an interactive session where each turn is small and the answer is being waited
on. Not worth it for long autonomous runs, where output speed is a small part of
wall-clock time and the price difference compounds.

## Long autonomous runs

The overnight fixture generation described in ADR-0003 is the case where these
choices compound rather than apply once.

Long-horizon work goes better with the full task specification given up front in
one well-specified instruction than assembled across turns. Run it at `high` or
`xhigh` — lower effort produces more turns, and more turns on an unattended run
costs more than the higher setting saved. Batch the work so each unit ends at a
gate that either passes or stops, rather than one run that discovers its own
failure at the end.

## What does not change with the model

The toolchain is the gate regardless of which model produced the code:

```bash
docker compose exec app ./vendor/bin/pint --test
docker compose exec app ./vendor/bin/phpstan analyse
docker compose exec app ./vendor/bin/pest
```

A cheaper model that produces green output is a correct choice. A more expensive
one that produces red output is not made acceptable by its price. Model
selection changes how often the first attempt passes; it does not change what
passing means.
