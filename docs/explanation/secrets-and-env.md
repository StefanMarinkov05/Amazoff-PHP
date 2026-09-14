# Secrets, `.env`, and what must never reach an AI agent

Standing policy, not a recipe. Where credentials live, who and what may read
them, and the specific rules that exist because this project is developed
with an AI coding agent in the loop.

This sits in `explanation/` rather than `how-to/` deliberately: it is a
policy that constrains every task, not a procedure for one. The procedures
that follow from it are in `how-to/set-up-stripe.md` (getting keys) and
`how-to/use-ci.md` (CI configuration).

## The rule, in one line

**`.env` is never read, printed, pasted, or committed — by anyone, and
especially not by an agent.** `.env.example` is the only environment file
that exists as far as tooling, agents, and version control are concerned.

## Why an agent makes this sharper than usual

A human who opens `.env` reads it and closes it. An agent that reads `.env`
does something categorically worse: **the contents enter a conversation
transcript**, which is stored, may be used to improve models depending on
the plan, may be pasted into an issue, and is trivially re-read by the next
person with access to the session. A key in a transcript is a key you cannot
un-leak by rotating the file.

Three concrete rules follow.

### 1. Agents read `.env.example`, never `.env`

`.env.example` is committed, contains no values, and documents every key
with a comment. It is the right file for an agent to consult, and it is
sufficient for every task an agent legitimately does — writing config,
adding a key, explaining setup.

If an agent needs to know whether a key is *set*, the answer is a boolean
and it should be obtained as one:

```bash
# Fine — reveals nothing.
docker compose exec app php artisan tinker --execute \
  'echo config("services.stripe.secret") ? "set" : "blank";'

# Never. Prints the key into the transcript.
cat .env | grep STRIPE
```

The same applies to `php artisan config:show`, which prints resolved
values, and to `env`, `printenv`, and `docker compose config`.

### 2. Never write a real value into a file an agent will read

That includes test fixtures. Every secret in this repository's tests is a
literal placeholder — `whsec_test_secret_for_signature_verification`,
`whsec_new_secret` — chosen so that a leaked test file leaks nothing. A
real `whsec_` pasted into a test to "make it work" is a live credential in
version control.

### 3. If a key is exposed, rotate it — do not delete the message

Removing a message from a transcript, amending a commit, or force-pushing
does not un-publish a key. Assume anything that reached a transcript, a log,
a screenshot, or a CI output is compromised, and rotate:

| Credential | Where to rotate |
|---|---|
| `STRIPE_SECRET` / `STRIPE_KEY` | <https://dashboard.stripe.com/test/apikeys> — roll, then update `.env` |
| `STRIPE_WEBHOOK_SECRET` | Dashboard → Webhooks → the endpoint → ⋯ → **Roll secret** |
| `APP_KEY` | `php artisan key:generate` — **note this invalidates every encrypted value and session** |
| Database password | `docker-compose.yml` + `.env`, then `migrate:fresh` locally |

Stripe's webhook roll gives a 24-hour overlap window, which is exactly what
`STRIPE_WEBHOOK_SECRET_PREVIOUS` exists for — see
`explanation/stripe-payments.md`.

## What lives where

| Kind | Where | Committed? |
|---|---|---|
| Key names, comments, empty values | `.env.example` | yes |
| Real local values | `.env` | **no** — gitignored |
| Real CI values | GitHub Actions repository secrets | no — stored encrypted by GitHub |
| Real production values | The host's own secret store | no |

There is deliberately **no fourth option**. A `.env.production` in the repo,
a key in `docker-compose.yml`, a token in a comment "just for now" — each is
the same mistake with a different filename.

## CI: GitHub Actions secrets

CI needs no Stripe credentials today, and that is worth preserving: every
payment test fakes `StripeClient`, so the suite runs with the keys blank.
`ci.yml` copies `.env.example` and generates an app key, and nothing else.

If a test ever genuinely needs a live test-mode key — an end-to-end smoke
test against Stripe's sandbox, say — the mechanism is a repository secret,
never a value in the workflow file:

1. Repository → Settings → Secrets and variables → Actions → **New
   repository secret**.
2. Reference it as an env var in the step that needs it:

   ```yaml
   - name: Run payment smoke test
     env:
       STRIPE_SECRET: ${{ secrets.STRIPE_TEST_SECRET }}
     run: ./vendor/bin/pest --group=stripe-live
   ```

Three things that matter about that:

- **Secrets are masked in logs**, but only by exact match. A key that gets
  base64-encoded, substringed, or embedded in a JSON blob before being
  printed is *not* masked. Do not `echo` anything derived from a secret.
- **Secrets are not available to workflows triggered by `pull_request` from
  a fork.** That is a feature — it stops a drive-by PR exfiltrating them —
  and it means any secret-dependent job must tolerate being skipped.
- **Use test-mode keys only.** A live key in CI is a live key in every
  future workflow run's environment.

## Production: not GitHub secrets

GitHub Actions secrets are for CI. Production credentials belong in whatever
the host provides, because those systems give you rotation, audit, and
per-environment scoping that a CI secret store does not:

- **Laravel Forge** (ADR-0001's deployment target) — environment editor per
  site, values encrypted at rest, changes logged.
- **Laravel Cloud** — environment variables per environment.
- A generic VPS — systemd `EnvironmentFile=` with `0600` ownership, or
  HashiCorp Vault / AWS Secrets Manager / Doppler if the team ever wants
  short-lived credentials and an audit trail.

The distinction that matters: CI secrets are read by a build, production
secrets are read by a running application, and conflating them means a CI
compromise is a production compromise.

## Checklist before any commit

- [ ] `git status` shows no `.env`, `.env.local`, `.env.production`
- [ ] No `sk_live_`, `pk_live_`, `whsec_`, or `rk_` string in the diff
- [ ] Test fixtures use obvious placeholders, not real values
- [ ] No `config:show`, `printenv`, or `cat .env` output pasted into a PR
      description, commit message, or issue
- [ ] `.env.example` updated if a new key was added — with an empty value
      and a comment saying where to get it

A fast mechanical check:

```bash
git diff --cached | grep -nE '(sk|pk|rk)_(live|test)_[A-Za-z0-9]{8,}|whsec_[A-Za-z0-9]{8,}'
```

Empty output is what you want. This catches the common shapes, not every
possible secret — it is a backstop for the checklist, not a replacement.

## Related

- `explanation/security-model.md` — authorization, and the webhook's own
  security properties.
- `explanation/gdpr.md` — personal data, which is a different problem with
  overlapping rules.
- `how-to/set-up-stripe.md` — obtaining the keys this page tells you to
  protect.
- Implementation standard #17 — keys out of `.env.example`, which this page
  extends rather than restates.
