#!/usr/bin/env bash
# Assert the Artha white-label layer survived an upstream merge for Artha Docs
# (BookStack fork). Fails loudly if Artha branding is missing OR if the upstream
# "BookStack" product name leaks into user-facing defaults — the pipeline calls
# this before it is ever allowed to deploy, so production is never touched on drift.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
cd "$ROOT"

fail() { printf '\n !! verify-rebrand: %s\n' "$*" >&2; exit 1; }
ok()   { printf '    ok: %s\n' "$*"; }

# 1. Artha integration code must be present.
[ -f app/Artha/SsoController.php ]        || fail "app/Artha/SsoController.php missing"
[ -f app/Artha/ArthaServiceProvider.php ] || fail "app/Artha/ArthaServiceProvider.php missing"
[ -f app/Config/artha.php ]               || fail "app/Config/artha.php missing"
ok "Artha SSO integration files present"

# 2. The Artha provider must stay registered in the app config.
grep -qF 'BookStack\Artha\ArthaServiceProvider::class' app/Config/app.php \
  || fail "ArthaServiceProvider not registered in app/Config/app.php"
ok "ArthaServiceProvider registered"

# 3. Brand defaults must point at Artha, env-driven, with Artha colours.
grep -qF "env('APP_NAME', 'Artha Docs')" app/Config/setting-defaults.php \
  || fail "app-name default is not 'Artha Docs'"
grep -qF "'#202870'" app/Config/setting-defaults.php \
  || fail "Artha indigo #202870 missing from setting-defaults"
ok "brand defaults are Artha (name + indigo #202870)"

# 4. Brand assets must exist.
[ -f public/artha-logo.png ] || fail "public/artha-logo.png missing"
ok "brand assets present"

# 5. The upstream product name must NOT leak into the shipped default app name.
if grep -qE "=>\s*env\('APP_NAME',\s*'BookStack'\)" app/Config/setting-defaults.php; then
  fail "upstream 'BookStack' app-name default leaked back in after merge"
fi
ok "no upstream 'BookStack' app-name default leak"

printf '\n==> verify-rebrand: Artha Docs branding intact.\n'
