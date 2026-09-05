# How to test in a clean browser, and as a phone

Two things the default browser session does not give you: a **guaranteed
empty starting state**, and a **real device profile**. Both matter for this
project — a cart persists in a cookie, and "responsive" is a claim about
phones rather than about a narrow desktop window.

`docs/reference/testing/browser-testing.md` records what has been verified and what
has not. This page is how to run it yourself.

## The problem with the default session

The browser keeps a profile on disk between runs. That is convenient for
poking around and wrong for testing, because state leaks from one run to the
next:

- A cart from a previous session is still in the cookie, so "add one item
  and check the cart" starts from three items.
- A logged-in Filament session means an authorization check appears to pass
  when it would have redirected a fresh visitor.
- A dismissed banner stays dismissed, so it is never seen again.

Resizing a desktop window has a second, subtler problem: it changes the
viewport and nothing else. The browser still sends a desktop `User-Agent`,
still reports a mouse rather than a touchscreen, and still uses a
device-pixel-ratio of 1. A media query on `pointer: coarse` or
`hover: none` — the correct way to ask "is this a touch device" — does not
fire. So the layout is exercised while the device detection is not.

## Isolated sessions

Add `--isolated` and the profile is held in memory and discarded when the
server stops. Every session starts with no cookies, no local storage, no
session.

Edit the `playwright-chromium` entry in `.mcp.json`:

```json
"playwright-chromium": {
  "command": "npx",
  "args": [
    "-y", "@playwright/mcp@latest",
    "--browser", "chromium",
    "--isolated"
  ]
}
```

Restart the Claude Code session afterwards — MCP servers register at start,
so a changed `.mcp.json` does nothing until then.

**Trade-off worth knowing.** Isolation is right for a repeatable test and
annoying for exploration: you re-log-in to `/admin` every session. Two
options if that becomes tiresome — leave `--isolated` off for exploratory
work and add it when verifying, or keep it on and hand it a saved login with
`--storage-state <path>`, which loads cookies and local storage into an
otherwise-clean profile.

## Emulating a device

`--device` takes a Playwright device name and sets viewport, user agent,
device pixel ratio, and touch support together:

```json
"args": [
  "-y", "@playwright/mcp@latest",
  "--browser", "chromium",
  "--isolated",
  "--device", "iPhone 15"
]
```

`--mobile` is the shortcut — a generic phone profile (Pixel 10 on Chromium)
without naming a model. It cannot be combined with `--device`.

`--viewport-size 1280x720` sets only the viewport, which is the right choice
when you want a specific desktop width and nothing else changed.

### A caveat this project should not gloss over

Emulating an iPhone in Chromium sets iPhone *metrics* — viewport, pixel
ratio, touch, user agent. **It does not run WebKit.** Safari's own layout
and CSS bugs are precisely what iOS testing is for, and they are not
reproduced by a Chromium instance wearing an iPhone user agent.

For genuine Safari behaviour the server has to launch WebKit
(`--browser webkit`, a separate browser download). Until that is run,
`browser-testing.md`'s "one browser" limitation stands, and an iPhone
emulation result should be described as *iPhone metrics*, not as *iOS*.

## Choosing between the two ways to change size

| Approach | Changes | Use it for |
|---|---|---|
| `browser_resize` tool | viewport only, mid-session | Sweeping several widths in one run — what the §37 #19 check did |
| `--device` / `--mobile` | viewport, UA, DPR, touch | Verifying touch behaviour, device detection, `pointer: coarse` media queries |
| `--viewport-size` | viewport only, at launch | Pinning one fixed desktop size for every run |

The resize tool is the practical default: three widths in a single session
costs three tool calls, where three device profiles costs three restarts.
Reach for `--device` when what is under test is *device-ness* rather than
width.

## A clean run, start to finish

The app must be up and seeded — the browser reaches it over the published
port, so nothing works against a stopped container:

```bash
docker compose up -d
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan db:seed --class="Database\Seeders\Demo\DemoSeeder"
```

Then, in a session where `.mcp.json` carries `--isolated`:

1. Navigate to `http://localhost:8080/catalogue`.
2. **Confirm the styling loaded before believing any measurement** — count
   `document.styleSheets`, check `window.Alpine` is defined, check the grid
   container computes `display: grid`. An unstyled document never overflows,
   so skipping this step manufactures false passes.
   `browser-testing.md` has the reasoning; it is the browser-side form of
   "a test that has never been observed failing proves nothing".
3. Run the check, then resize and repeat.

Seeded logins for `/admin` are in `reference/permissions.md` — four demo
accounts, password `password`, non-production only. With `--isolated` you
will need them every session.

## Where output lands

Console logs, page snapshots, and screenshots are written to
`.playwright-mcp/`, which is gitignored — it fills with per-session files
that are regenerated on every run and are not source.

A screenshot worth keeping is moved into `docs/assets/` by hand and
referenced from a doc, the way `browser-testing.md` references the 375 px
catalogue capture. Nothing under `.playwright-mcp/` should be linked from
documentation; it will not survive the next run.
