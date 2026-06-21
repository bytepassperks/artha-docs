# Artha Docs — deploy/artha kit

Artha Docs is a thin white-label fork of [BookStack](https://github.com/BookStackApp/BookStack)
that tracks upstream and ships to Scalingo with the Artha brand layer preserved.
This is the same fork-tracks-upstream model used by the other Artha modules
(Accounting/erpsaas, Automations/Activepieces).

## Pieces

- **`update.sh`** — the gated auto-update pipeline. Fetches `upstream/release`,
  trial-merges on a throwaway branch, runs `verify-rebrand.sh`, rebuilds assets,
  then (only if everything is clean) merges for real, deploys to Scalingo,
  migrates, verifies the live login, and pins the upstream SHA in `VERSION`.
  Any conflict or brand drift **stops before deploy** — production is never
  touched on failure.
- **`verify-rebrand.sh`** — asserts the Artha layer is intact (SSO package
  registered, brand defaults = "Artha Docs" + indigo `#202870`, logo present)
  and that the upstream "BookStack" product name has not leaked back into the
  shipped default app name.
- **`VERSION`** — the upstream `release` SHA currently deployed.

## Usage

```bash
# safe pre-flight (no branch mutation, no deploy):
GH_TOKEN=... deploy/artha/update.sh --dry-run

# real update + deploy:
GH_TOKEN=... SCALINGO_API_TOKEN=... deploy/artha/update.sh
```

## The Artha layer (what makes this "Artha Docs", not "BookStack")

- `app/Artha/` — `ArthaServiceProvider` (registers the SSO route) and
  `SsoController` (verifies the CRM-minted HMAC token, auto-provisions/logs in
  the user). Registered in `app/Config/app.php`.
- `app/Config/artha.php` — `artha.sso.enabled` / `artha.sso.secret` (env-driven).
- `app/Config/setting-defaults.php` — brand defaults are env-driven and default
  to "Artha Docs", logo `/artha-logo.png`, indigo `#202870` / gold `#E0B030`.
- `public/artha-logo.png`, `public/artha-mark.svg`, `public/favicon.ico` — assets.

Everything is additive, so upstream BookStack keeps merging cleanly.
