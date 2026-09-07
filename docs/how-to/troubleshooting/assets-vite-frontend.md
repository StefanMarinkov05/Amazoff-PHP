# Troubleshooting — Vite, Tailwind, and fonts

Part of the [troubleshooting index](../troubleshooting.md). Errors where a
page renders but the asset pipeline didn't do what it looks like it did.

---

## Tailwind silently stops compiling new classes, and the page looks half-styled

**Symptom.** The storefront renders with *some* styling: colours and base
utilities land, but icons appear at their natural SVG size (a 16px chevron
drawn 250px tall), a responsive grid never leaves one column, and no hover
or entrance state fires. It reads as "the UI is bugged" rather than "no CSS
loaded", because plenty of CSS did load.

The tell: two classes in the *same* attribute behave differently.
`grid-cols-2` renders and `lg:grid-cols-3` beside it does not.

**Cause.** Tailwind 4 anchors automatic source detection at the **git root**.
This repository keeps `.git` one level above `online-store/`, and
`docker-compose.yml` mounts only `./online-store` into the container — so
from inside, there is no git root to find and automatic detection collapses
to whatever `@source` lines exist.

`resources/css/app.css` shipped with two, from the Laravel starter kit:

```css
@source '.../Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';   /* the compiled Blade cache */
```

Neither covers `resources/views`. Tailwind was reading **compiled Blade
output** rather than Blade source, so a class only existed in the stylesheet
if some page carrying it had already been rendered *and* the cache had not
been cleared since. Running `php artisan view:clear` — reasonable for any
number of unrelated reasons — empties the only source Tailwind is reading
and breaks classes that worked a minute earlier.

**Fix.** Scan the source, not the build artifact:

```css
@source '../views/**/*.blade.php';
@source '../../app/Livewire/**/*.php';
```

The explicit glob matters; the bare directory form `@source '../views'` did
not match here.

**Why it recurs.** The failure is partial, which sends you looking at the
markup. Every instinct says "a class is wrong" when the class is fine and
was never compiled. It also comes back on its own: any future
`view:clear`, or a new component whose page has not been rendered yet,
reproduces it exactly.

**Prevention.** When a utility appears not to apply, check whether it is in
the compiled stylesheet before touching the template:

```bash
curl -s "http://localhost:5173/resources/css/app.css" | grep -c 'grid-cols-3'
```

Zero means a source-scanning problem, not a markup problem. Note that in
dev Vite serves CSS **wrapped in a JS module** — the whole sheet is one
line with `\n` escapes and `\:` for escaped colons, so line-oriented
counting (`grep -c '@media'`) reports 1 no matter what is in it. Count
substrings, not lines.

---

## Vite serves assets the browser cannot reach, and the page renders unstyled

**Symptom.** `/catalogue` returns 200 with correct HTML and no PHP error, but
no CSS or JS applies. `curl` against the Vite port succeeds, so the dev
server is plainly running.

**Cause.** `public/hot` contained `http://0.0.0.0:5173`. That is a bind-all
address: meaningful to a listening socket inside the container, meaningless
to a browser. Every asset request failed, and because the failures are
network-level the page renders as bare HTML with nothing in the PHP log.

**Fix.** Tell the plugin what the *browser* should use, separately from what
the server binds to — `online-store/vite.config.js`:

```js
server: {
    host: '0.0.0.0',          // bind inside the container
    origin: 'http://localhost:5173',  // what goes into public/hot
    hmr: { host: 'localhost' },
},
```

**Why it recurs.** `curl http://localhost:5173/resources/css/app.css` returns
200 from the host, which looks like proof the assets are fine — but the host
and the browser resolve `0.0.0.0` differently from the container. Any fresh
clone, or anyone who deletes `public/hot`, gets it again.

**Prevention.** Check what `@vite` actually emitted rather than whether the
port answers:

```bash
cat online-store/public/hot          # must read http://localhost:5173
curl -s http://localhost:8080/catalogue | grep -o 'src="http://[^"]*5173[^"]*"'
```

---

## The webfont never loads, and every heading falls back to the system font

**Symptom.** Type looks generic and slightly wrong — weights are close but
letterforms are not the ones the design assumes. No error anywhere, and
`public/fonts-manifest.dev.json` exists with correct `@font-face` rules.

**Cause.** `Vite::fonts()` is a separate call. `@vite(['…css', '…js'])` does
**not** inject the font manifest, so the `laravel-vite-plugin/fonts` output is
generated and then never referenced.

**Fix.** In the layout `<head>`, alongside `@vite`:

```blade
{{ Vite::fonts() }}
@vite(['resources/css/app.css', 'resources/js/app.js'])
```

**Why it recurs.** The manifest existing is the misleading part: the fonts
pipeline looks configured and working, because the half that generates
output is. Nothing warns that nothing consumes it, and a fallback font is
legible enough that the page does not look broken — only slightly off.

**Prevention.** Grep the rendered head, not the manifest:

```bash
curl -s http://localhost:8080/catalogue | grep -c '@font-face'
```

Zero means it is not wired, regardless of what is in `public/`.
