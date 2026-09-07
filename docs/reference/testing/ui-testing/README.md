# UI testing — interactive click-through, one file per pass

Part of the family `../stripe-testing.md`, `../browser-testing.md`,
`../security-testing/`, and `../security-tooling.md` belong to: a dated
record of a manual pass, what it verified, and what it did not reach.
Screenshots live in `../../../assets/ui-testing/`, not loose in `assets/`.

- **[phase-1-storefront-clickthrough.md](phase-1-storefront-clickthrough.md)**
  — every storefront page driven live, every defined behaviour (a honeypot,
  a validation error, a 404) screenshotted next to the claim it proves, and
  the missing-pages inventory checked by `curl`, not inferred from routes.
- **[phase-2-admin-created-data.md](phase-2-admin-created-data.md)** — the
  same discipline from the admin side: real broken catalogue data created
  through the actual Actions, and what the customer's browser does with it.
- **[phase-3-error-leak-sweep.md](phase-3-error-leak-sweep.md)** — what an
  *unhandled* failure actually shows an anonymous visitor — verbose traces,
  leaked paths, an attribute-breakout case checked against rendered output,
  not assumed safe from source.
- **[phase-4-admin-panel-clickthrough.md](phase-4-admin-panel-clickthrough.md)**
  — inside the panel: the role-denial matrix at all 19 resources, whether a
  status menu is enforced or merely hidden, and the six bare `DeleteAction`s
  that turn a foreseeable click into an uncaught `QueryException`.
