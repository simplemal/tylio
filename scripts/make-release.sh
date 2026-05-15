#!/usr/bin/env bash
# Cut a tylio release.
#
# Steps (each is idempotent — re-running after a partial failure resumes):
#
#   1. Validate the version arg against semver.
#   2. Update BUILD (release marker) and .version (timestamp).
#   3. Prepend a new entry to CHANGELOG.md and open it in $EDITOR so the
#      user can fill in the notes. The release notes for the GitHub
#      release come from the entry block the user just wrote.
#   4. composer install --no-dev --optimize-autoloader → refresh
#      composer.lock for the release.
#   5. (admin-src) npm install && npm run build → refresh the bundled
#      SPA so users without Node can drop the admin/ tarball in place.
#   6. Tar the admin/ directory into a release asset.
#   7. git commit + git tag (annotated).
#   8. Prompt before pushing to origin (`main` + tag).
#   9. Prompt before creating the GitHub release via `gh release create`,
#      attaching the admin/ tarball as an asset.
#
# Re-entry: if step N already happened on a prior run, that step is a
# no-op (existing files, existing commit message, existing tag). The
# script never destroys an existing tag — pass `--force-tag` if you
# need to recreate one (only do this BEFORE pushing).
#
# Usage:
#   scripts/make-release.sh v0.1.0
#   scripts/make-release.sh 0.1.0           (the `v` prefix is added automatically)
#   scripts/make-release.sh v0.1.0 --force-tag
#
# Required tools: git, composer, npm, tar, gh (for the GitHub release
# step — can be skipped if `gh` is not installed).

set -euo pipefail

# ---------- helpers ----------------------------------------------------
log()  { printf '\033[1;32m▶\033[0m %s\n' "$*" >&2; }
warn() { printf '\033[1;33m⚠\033[0m %s\n' "$*" >&2; }
err()  { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; }
die()  { err "$*"; exit 1; }
confirm() {
  local prompt="${1:-Continue?}"
  read -rp "$prompt [y/N] " ans
  [[ "$ans" =~ ^[Yy] ]]
}

# ---------- args -------------------------------------------------------
RAW_VERSION="${1:-}"
FORCE_TAG=0
shift || true
for a in "$@"; do
  case "$a" in
    --force-tag) FORCE_TAG=1 ;;
    *) die "Unknown flag: $a" ;;
  esac
done

[[ -z "$RAW_VERSION" ]] && die "Usage: $0 <version> [--force-tag]   (e.g. $0 v0.1.0)"

# Normalize: strip a leading `v`, then re-add. Accept semver with
# optional `-suffix` (e.g. v0.1.0-rc.1) and optional `+build`.
NORMALIZED="${RAW_VERSION#v}"
if [[ ! "$NORMALIZED" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$ ]]; then
  die "Version '$RAW_VERSION' is not valid semver (expected vMAJOR.MINOR.PATCH[-pre][+build])"
fi
VERSION="v$NORMALIZED"
log "Releasing $VERSION"

# ---------- locate project root ----------------------------------------
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

[[ -d .git ]] || die "$PROJECT_ROOT is not a git repository"
[[ -f composer.json ]] || die "composer.json missing — wrong project root?"

# ---------- working tree must be clean (except admin/ build) -----------
# admin/ is .gitignored and rebuilt during this script. Anything else
# uncommitted would silently end up in the release commit — refuse.
if ! git diff --quiet || ! git diff --cached --quiet; then
  err "Working tree has uncommitted changes:"
  git status --short >&2
  die "Commit or stash them before cutting a release."
fi

# ---------- 1. BUILD + .version files ----------------------------------
TS="$(date -u +%Y-%m-%d-%H%M%S)"
log "Writing BUILD = $VERSION"
printf '%s\n' "$VERSION" > BUILD
log "Writing .version = $TS"
printf '%s\n' "$TS" > .version

# ---------- 2. CHANGELOG entry -----------------------------------------
TODAY="$(date -u +%Y-%m-%d)"
CHANGELOG=CHANGELOG.md
ENTRY_HEADER="## $VERSION — $TODAY"

if [[ ! -f "$CHANGELOG" ]]; then
  log "Creating $CHANGELOG"
  cat > "$CHANGELOG" <<EOF
# Changelog

All notable changes to tylio are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

EOF
fi

if grep -qE "^## $VERSION " "$CHANGELOG"; then
  warn "Entry for $VERSION already present in $CHANGELOG (skipping prepend)."
else
  log "Prepending entry for $VERSION to $CHANGELOG"
  # Auto-generated notes: gather feat:/fix:/chore(release) commits since
  # the previous tag (or, on first release, since the repo's initial
  # commit). The user can edit them in $EDITOR right after.
  PREV_TAG="$(git tag --sort=-creatordate | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)"
  if [[ -n "$PREV_TAG" ]]; then
    RANGE="$PREV_TAG..HEAD"
  else
    RANGE=""
  fi
  AUTO_NOTES="$(
    if [[ -n "$RANGE" ]]; then
      git log --no-merges --pretty='format:- %s' "$RANGE" 2>/dev/null
    else
      git log --no-merges --pretty='format:- %s' 2>/dev/null
    fi \
      | grep -E '^- (feat|fix)(\(|:)' \
      | head -50 \
      || true
  )"
  [[ -z "$AUTO_NOTES" ]] && AUTO_NOTES="- (edit me)"
  TMP_NEW="$(mktemp)"
  {
    # Preserve the file header (first 7 lines up to and including the blank
    # line after the "versioning:" sentence) before the new entry.
    head -n 7 "$CHANGELOG"
    echo
    echo "$ENTRY_HEADER"
    echo
    echo "### Notes"
    echo
    echo "$AUTO_NOTES"
    echo
    # Append the rest of the changelog (everything past the 7-line header).
    tail -n +8 "$CHANGELOG"
  } > "$TMP_NEW"
  mv "$TMP_NEW" "$CHANGELOG"
fi

# Open the changelog in $EDITOR so the user can refine the entry.
EDITOR_CMD="${EDITOR:-vi}"
if [[ -t 0 ]]; then
  log "Opening $CHANGELOG in $EDITOR_CMD — refine the notes, then save and exit."
  "$EDITOR_CMD" "$CHANGELOG"
else
  warn "Non-interactive shell — skipping editor; the auto-generated entry stays as-is."
fi

# Extract the section for THIS version (between this entry and the next
# `## v…` header). Used later as the body of the GitHub release.
RELEASE_NOTES_FILE="$(mktemp)"
awk -v hdr="$ENTRY_HEADER" '
  $0 == hdr { in_block=1; next }
  in_block && /^## v[0-9]+\./ { exit }
  in_block { print }
' "$CHANGELOG" > "$RELEASE_NOTES_FILE"

# ---------- 3. composer install ----------------------------------------
if command -v composer >/dev/null 2>&1; then
  log "Refreshing composer.lock (no-dev, optimised autoload)"
  composer install --no-dev --optimize-autoloader --quiet
else
  warn "composer not on PATH — skipping (refresh composer.lock manually on a build host)."
fi

# ---------- 4. admin-src build -----------------------------------------
if [[ -d admin-src ]] && command -v npm >/dev/null 2>&1; then
  log "Building the admin SPA (admin-src → admin/)"
  pushd admin-src >/dev/null
  npm install --no-audit --no-fund --silent
  npm run build --silent
  popd >/dev/null
else
  warn "admin-src/ missing or npm not on PATH — skipping SPA build."
fi

# ---------- 5. admin/ tarball ------------------------------------------
ASSET=""
if [[ -d admin ]]; then
  ASSET="tylio-admin-bundle-$VERSION.tar.gz"
  log "Packaging admin/ → $ASSET"
  tar -czf "$ASSET" admin
else
  warn "admin/ not built — the release will not ship an admin bundle asset."
fi

# ---------- 5b. full-source tarball (for in-app upgrade) ----------------
# This is the asset consumed by UpdateApplier when the admin clicks
# "Aggiorna ora" in Settings: it must contain everything needed to run
# tylio on a self-host with ZERO dependencies (no composer, no npm).
# Excluded paths are the runtime/local-state set the swap explicitly
# preserves (data/, uploads/, favicons/, .env, .git, plus the build
# artifacts themselves to avoid recursion).
SOURCE_ASSET="tylio-source-$VERSION.tar.gz"
log "Packaging full source → $SOURCE_ASSET (incl. vendor/ + admin/)"
SOURCE_STAGING="$(mktemp -d)"
SOURCE_STAGING_TYLIO="$SOURCE_STAGING/tylio"
mkdir -p "$SOURCE_STAGING_TYLIO"
# Use rsync to stage so we can declare excludes cleanly. Falls back to
# a cp-based path on systems without rsync (rare, but possible).
if command -v rsync >/dev/null 2>&1; then
  rsync -a \
    --exclude='.git/' \
    --exclude='admin-src/node_modules/' \
    --exclude='admin-src/dist/' \
    --exclude='data/' \
    --exclude='uploads/' \
    --exclude='favicons/' \
    --exclude='.env' \
    --exclude='tylio-admin-bundle-*.tar.gz' \
    --exclude='tylio-source-*.tar.gz' \
    --exclude='.phpstan-cache/' \
    --exclude='.phpunit.cache/' \
    --exclude='tests/' \
    --exclude='*.swp' --exclude='*.bak' --exclude='.DS_Store' \
    "$PROJECT_ROOT/" "$SOURCE_STAGING_TYLIO/"
else
  warn "rsync not installed — using cp for source staging (slower)"
  cp -a "$PROJECT_ROOT/." "$SOURCE_STAGING_TYLIO/"
  rm -rf "$SOURCE_STAGING_TYLIO/.git" \
         "$SOURCE_STAGING_TYLIO/admin-src/node_modules" \
         "$SOURCE_STAGING_TYLIO/admin-src/dist" \
         "$SOURCE_STAGING_TYLIO/data" \
         "$SOURCE_STAGING_TYLIO/uploads" \
         "$SOURCE_STAGING_TYLIO/favicons" \
         "$SOURCE_STAGING_TYLIO/tests" \
         "$SOURCE_STAGING_TYLIO/.phpstan-cache" \
         "$SOURCE_STAGING_TYLIO/.phpunit.cache"
  rm -f "$SOURCE_STAGING_TYLIO/.env" "$SOURCE_STAGING_TYLIO/"tylio-*.tar.gz
fi
# Sanity check the staging dir before tarring.
[[ -d "$SOURCE_STAGING_TYLIO/app" ]] || die "Source staging missing app/ — aborting"
[[ -f "$SOURCE_STAGING_TYLIO/public/index.php" ]] || die "Source staging missing public/index.php — aborting"
[[ -d "$SOURCE_STAGING_TYLIO/admin" ]] || warn "Source staging missing admin/ — in-app upgrade will deploy without the SPA"
# Tar with the wrapper "tylio/" dir so UpdateApplier's collapse-single-
# top-level step DTRT.
( cd "$SOURCE_STAGING" && tar -czf "$PROJECT_ROOT/$SOURCE_ASSET" tylio )
rm -rf "$SOURCE_STAGING"
log "Source asset ready: $SOURCE_ASSET ($(du -h "$SOURCE_ASSET" | cut -f1))"

# ---------- 6. commit --------------------------------------------------
# Note: BUILD, .version, admin/ and the tarball are gitignored on
# purpose (BUILD/.version cause merge conflicts on every release;
# admin/ is a build artifact rebuilt per environment; the tarball
# only lives on the GitHub release as an asset). They stay on disk
# but never enter a commit.
log "Staging release files"
git add CHANGELOG.md
if git ls-files --error-unmatch composer.lock >/dev/null 2>&1; then
  git add composer.lock
fi

if git diff --cached --quiet; then
  warn "Nothing new to commit — files already match the current HEAD."
else
  log "Committing chore(release): $VERSION"
  git commit -m "chore(release): $VERSION"
fi

# ---------- 7. tag -----------------------------------------------------
if git rev-parse "refs/tags/$VERSION" >/dev/null 2>&1; then
  if [[ "$FORCE_TAG" == "1" ]]; then
    warn "Recreating existing tag $VERSION (--force-tag)"
    git tag -d "$VERSION"
    git tag -a "$VERSION" -m "Release $VERSION"
  else
    warn "Tag $VERSION already exists — leaving it alone. Pass --force-tag to recreate."
  fi
else
  log "Tagging $VERSION"
  git tag -a "$VERSION" -m "Release $VERSION"
fi

# ---------- 8. push ----------------------------------------------------
echo
git log -1 --pretty='%h %s'
git show "$VERSION" --no-patch --pretty='%h %s' || true
echo
if confirm "Push commit + tag to origin (main + $VERSION)?"; then
  git push origin main
  git push origin "$VERSION"
else
  warn "Skipped push. Re-run later with: git push origin main && git push origin $VERSION"
  rm -f "$RELEASE_NOTES_FILE"
  exit 0
fi

# ---------- 9. GitHub release ------------------------------------------
if ! command -v gh >/dev/null 2>&1; then
  warn "gh CLI not installed — skipping GitHub release. Create it manually at"
  warn "  https://github.com/simplemal/tylio/releases/new?tag=$VERSION"
  rm -f "$RELEASE_NOTES_FILE"
  exit 0
fi

if gh release view "$VERSION" >/dev/null 2>&1; then
  warn "GitHub release $VERSION already exists — skipping creation."
else
  echo
  echo "--- Release notes preview ---"
  cat "$RELEASE_NOTES_FILE"
  echo "-----------------------------"
  if confirm "Create the GitHub release with the notes above?"; then
    # Build the asset list dynamically — both bundles are optional but
    # `tylio-source-*.tar.gz` is REQUIRED for the in-app upgrade flow
    # (UpdateApplier rejects releases without it).
    ASSETS=()
    [[ -n "$ASSET" && -f "$ASSET" ]] && ASSETS+=("$ASSET")
    [[ -f "$SOURCE_ASSET" ]] && ASSETS+=("$SOURCE_ASSET")
    if [[ ${#ASSETS[@]} -gt 0 ]]; then
      gh release create "$VERSION" "${ASSETS[@]}" \
        --title "$VERSION" \
        --notes-file "$RELEASE_NOTES_FILE"
    else
      gh release create "$VERSION" \
        --title "$VERSION" \
        --notes-file "$RELEASE_NOTES_FILE"
    fi
  else
    warn "Skipped GitHub release. Create it manually with:"
    warn "  gh release create $VERSION ${ASSET:+\"$ASSET\" }${SOURCE_ASSET:+\"$SOURCE_ASSET\" }--title \"$VERSION\" --notes-file <file>"
  fi
fi

rm -f "$RELEASE_NOTES_FILE"
log "Done."
