#!/usr/bin/env bash
# Auto-update pipeline for Artha Docs (BookStack fork, Laravel 12).
#
# Tracks upstream BookStackApp/BookStack (branch `release`) and ships new commits
# to the live Scalingo app with the thin Artha white-label layer preserved — the
# same fork-tracks-upstream model used by Artha's other rebranded repos.
#
# The rebrand is a set of isolated additions (an app/Artha/ integration package +
# env-driven brand defaults), not a hand-rewrite, so the vast majority of upstream
# commits merge cleanly. If a merge conflicts, or a merged commit overwrites a
# branded file, the pipeline STOPS LOUDLY (no deploy) and asks for a human —
# production is never touched in that case.
#
# Flow:
#   1. fetch upstream release
#   2. stop if already up to date (unless --force)
#   3. (dry-run) merge onto a throwaway branch to detect conflicts, run the
#      rebrand assertions and a real asset build, then discard
#   4. (real)   merge upstream into main, run rebrand assertions
#   5. rebuild front-end assets (npm ci && npm run production) and commit them
#   6. push main to the GitHub fork
#   7. deploy the archive to Scalingo
#   8. run migrations (scalingo run 'php artisan migrate --force')
#   9. verify the live login serves HTTP 200 with the Artha brand token
#  10. record the deployed upstream SHA in deploy/artha/VERSION
#
#   --dry-run   run steps 1-3 only (resolve + trial-merge + assert + build)
#   --force     redeploy even if no new upstream commits
#
# Required env:
#   GH_TOKEN              GitHub token with repo scope (push to the fork)
# Required for a real (non-dry-run) deploy:
#   SCALINGO_API_TOKEN    Scalingo API token (headless login)
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
cd "$ROOT"

# ── Configuration ─────────────────────────────────────────────────────────────
UPSTREAM_REMOTE="upstream"
UPSTREAM_BRANCH="release"
FORK_BRANCH="main"
SCALINGO_APP="artha-docs"
SCALINGO_REGION="osc-fr1"
LIVE_URL="https://artha-docs.osc-fr1.scalingo.io"
VERIFY_PATH="/login"
EXPECTED_TOKEN="Artha Docs"
VERSION_FILE="$HERE/VERSION"

DRY_RUN=0
FORCE=0

log()  { printf '\n==> %s\n' "$*"; }
warn() { printf '\n !  %s\n' "$*" >&2; }
die()  { printf '\n !! %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run) DRY_RUN=1 ;;
    --force)   FORCE=1 ;;
    -h|--help) sed -n '2,40p' "$0"; exit 0 ;;
    *)         die "unknown flag: $1" ;;
  esac
  shift
done

[ -n "${GH_TOKEN:-}" ] || die "GH_TOKEN is required (repo scope)."

# ── Step 1: fetch upstream ────────────────────────────────────────────────────
git remote get-url "$UPSTREAM_REMOTE" >/dev/null 2>&1 \
  || die "git remote '$UPSTREAM_REMOTE' is not configured."
log "Fetching $UPSTREAM_REMOTE/$UPSTREAM_BRANCH"
git fetch --quiet "$UPSTREAM_REMOTE" "$UPSTREAM_BRANCH"

UPSTREAM_SHA="$(git rev-parse "$UPSTREAM_REMOTE/$UPSTREAM_BRANCH")"
RECORDED_SHA="$(tr -d '[:space:]' < "$VERSION_FILE" 2>/dev/null || true)"
log "recorded upstream: ${RECORDED_SHA:-<none>}   latest upstream: $UPSTREAM_SHA"

# ── Step 2: up-to-date short-circuit ──────────────────────────────────────────
if git merge-base --is-ancestor "$UPSTREAM_SHA" HEAD 2>/dev/null && [ "$FORCE" -ne 1 ]; then
  log "Already contains upstream $UPSTREAM_SHA — nothing to do."
  exit 0
fi

if [ -n "$(git status --porcelain)" ]; then
  die "working tree is dirty — commit or stash before running the pipeline."
fi
git checkout --quiet "$FORK_BRANCH"

# ── Step 3: dry-run trial merge on a throwaway branch ─────────────────────────
if [ "$DRY_RUN" -eq 1 ]; then
  TRIAL="artha-trial-$(date +%s)"
  log "DRY-RUN: trial-merging upstream onto $TRIAL"
  git checkout --quiet -b "$TRIAL"
  cleanup_trial() { git merge --abort 2>/dev/null || true; git checkout --quiet "$FORK_BRANCH"; git branch -D "$TRIAL" 2>/dev/null || true; }
  trap cleanup_trial EXIT
  if ! git merge --no-edit --no-ff "$UPSTREAM_REMOTE/$UPSTREAM_BRANCH" >/dev/null 2>&1; then
    git merge --abort 2>/dev/null || true
    die "DRY-RUN: merge CONFLICT against upstream $UPSTREAM_SHA — needs a human to reconcile the rebrand layer. Production untouched."
  fi
  "$HERE/verify-rebrand.sh"
  log "DRY-RUN: building assets to prove they compile against the merged tree"
  npm ci --no-audit --no-fund
  npm run production
  log "DRY-RUN complete: upstream $UPSTREAM_SHA merges cleanly, rebrand intact, assets build. Safe to ship."
  exit 0
fi

# ── Step 4: real merge ────────────────────────────────────────────────────────
log "Merging upstream $UPSTREAM_SHA into $FORK_BRANCH"
if ! git merge --no-edit --no-ff "$UPSTREAM_REMOTE/$UPSTREAM_BRANCH"; then
  git merge --abort 2>/dev/null || true
  die "merge CONFLICT against upstream $UPSTREAM_SHA — needs a human to reconcile the rebrand layer. Production untouched."
fi
"$HERE/verify-rebrand.sh" || die "rebrand drifted after merge — fix deploy/artha before shipping. Production untouched."

# ── Step 5: rebuild + commit assets ───────────────────────────────────────────
log "Rebuilding front-end assets"
npm ci --no-audit --no-fund
npm run production
git add -f public/dist
git add -A
if ! git diff --cached --quiet; then
  git commit -m "Rebuild assets after upstream merge ($UPSTREAM_SHA)"
fi

# ── Step 6: push to the fork ──────────────────────────────────────────────────
log "Pushing $FORK_BRANCH to origin"
git push origin "$FORK_BRANCH"

# ── Step 7: deploy archive to Scalingo ────────────────────────────────────────
[ -n "${SCALINGO_API_TOKEN:-}" ] || die "SCALINGO_API_TOKEN is required for deploy."
export SCALINGO_REGION
log "Logging in to Scalingo"
scalingo login --api-token "$SCALINGO_API_TOKEN" >/dev/null

TARBALL="$(mktemp -d)/artha-docs.tar.gz"
git archive --format=tar.gz --prefix=artha-docs/ HEAD -o "$TARBALL"
log "Deploying archive ($(du -h "$TARBALL" | cut -f1)) to $SCALINGO_APP"
scalingo --app "$SCALINGO_APP" deploy "$TARBALL" "artha-$(git rev-parse --short HEAD)-$(date +%s)"

# ── Step 8: migrate ───────────────────────────────────────────────────────────
log "Running database migrations"
scalingo --app "$SCALINGO_APP" --region "$SCALINGO_REGION" run --silent 'php artisan migrate --force'

# ── Step 9: verify live ───────────────────────────────────────────────────────
log "Verifying live app at $LIVE_URL$VERIFY_PATH"
ok=0
for _ in $(seq 1 30); do
  code="$(curl -s -o /dev/null -w '%{http_code}' "$LIVE_URL$VERIFY_PATH" || true)"
  if [ "$code" = "200" ] && curl -s "$LIVE_URL$VERIFY_PATH" | grep -qF "$EXPECTED_TOKEN"; then
    ok=1; break
  fi
  sleep 10
done
[ "$ok" -eq 1 ] || die "post-deploy verification failed: $LIVE_URL$VERIFY_PATH did not return HTTP 200 with '$EXPECTED_TOKEN'."
log "live: HTTP 200 + '$EXPECTED_TOKEN' present."

# ── Step 10: record deployed upstream SHA ─────────────────────────────────────
echo "$UPSTREAM_SHA" > "$VERSION_FILE"
git add "$VERSION_FILE"
git commit -m "Record deployed upstream BookStack SHA $UPSTREAM_SHA"
git push origin "$FORK_BRANCH"
log "DONE. Artha Docs updated to upstream $UPSTREAM_SHA and live."
