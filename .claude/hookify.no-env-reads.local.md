---
name: block-env-file-reads
enabled: true
event: bash
action: block
conditions:
  - field: command
    operator: regex_match
    pattern: (?i)\b(cat|head|tail|less|more|bat|nl|od|xxd|strings|grep|rg|awk|sed|cp|scp|source)\b[^|;&\n]*?(?:^|[\s=])(?:[\w./-]*/)?\.env(?![.\w-])
---

🔐 **`.env` is never read, printed, or pasted**

`docs/explanation/secrets-and-env.md` is the standing policy:

> `.env` is never read, printed, pasted, or committed — by anyone, and
> especially not by an agent.
> Agents read `.env.example`, never `.env`.

Reading it puts live credentials into the transcript, where they persist
beyond this session.

**What to do instead:**

- Need to know **which** keys exist → read `.env.example`
- Need to know whether a key is **set** → `php artisan tinker` and check
  `config('services.stripe.key') !== ''`, which reveals presence without the value
- Need to **change** a value → tell the user which key to set; they edit it

`.env.example` is explicitly allowed and this rule does not match it.
