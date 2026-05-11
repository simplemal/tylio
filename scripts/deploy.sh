#!/usr/bin/env bash
# Generic rsync-over-SSH deployment for tylio.
#
# This is an OPTIONAL convenience script — feel free to ignore it and use
# `git pull && composer install --no-dev && (cd admin-src && npm run build) &&
# php scripts/migrate.php` directly on the server. The script exists for
# setups where the dev machine can SSH to the server but the server can't
# pull from a git remote.
#
# Required environment variables:
#   TYLIO_SSH_TARGET   e.g. "deploy@example.com" (the SSH user with sudo)
#   TYLIO_REMOTE_ROOT  e.g. "/var/www/tylio" (project root on the server)
#
# Usage:
#   TYLIO_SSH_TARGET=user@host TYLIO_REMOTE_ROOT=/var/www/tylio \
#     ./scripts/deploy.sh [--skip-build]
set -euo pipefail

: "${TYLIO_SSH_TARGET:?must be set (e.g. user@host)}"
: "${TYLIO_REMOTE_ROOT:?must be set (e.g. /var/www/tylio)}"

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap "rm -rf $TMP_DIR" EXIT

skip_build=0
for a in "$@"; do
  case "$a" in
    --skip-build) skip_build=1 ;;
  esac
done

# 1) Build the admin SPA locally so the server doesn't need Node.
if [ $skip_build -eq 0 ]; then
  echo "==> Building admin SPA"
  ( cd "$PROJECT_DIR/admin-src" && [ -d node_modules ] || npm install ) >&2
  ( cd "$PROJECT_DIR/admin-src" && npm run build )
fi

# 2) Stage the files to push (everything except dev-only / runtime dirs).
echo "==> Staging at $TMP_DIR"
rsync -a \
  --exclude='.git/' \
  --exclude='admin-src/node_modules/' \
  --exclude='admin-src/dist/' \
  --exclude='data/' \
  --exclude='uploads/' \
  --exclude='favicons/' \
  --exclude='public/' \
  --exclude='vendor/' \
  --exclude='tests/' \
  --exclude='.phpstan-cache/' \
  --exclude='.phpunit.cache/' \
  --exclude='*.swp' --exclude='*.bak' --exclude='.DS_Store' \
  "$PROJECT_DIR/" "$TMP_DIR/"

# 3) Push to the remote staging dir, then atomically swap on the server.
echo "==> Pushing to $TYLIO_SSH_TARGET:$TYLIO_REMOTE_ROOT/.deploy/"
rsync -az --delete -e "ssh -o BatchMode=yes" \
  "$TMP_DIR/" "$TYLIO_SSH_TARGET:$TYLIO_REMOTE_ROOT/.deploy/"

# 4) Activate: rsync from the staging dir into the live tree, preserving
# data/ uploads/ favicons/ and the local .env. Then composer install and
# migrate as the webserver user. The remote user must have sudo for
# `chown` / `composer` / `php` as `www-data` (adjust as needed).
ssh "$TYLIO_SSH_TARGET" 'bash -s' <<REMOTE
set -euo pipefail
ROOT=$TYLIO_REMOTE_ROOT
sudo rsync -a --delete \
  --exclude='data/' --exclude='uploads/' --exclude='favicons/' --exclude='.env' \
  \$ROOT/.deploy/ \$ROOT/
sudo rm -rf \$ROOT/.deploy
[ -f \$ROOT/.env ] || sudo cp \$ROOT/.env.example \$ROOT/.env
sudo mkdir -p \$ROOT/data \$ROOT/data/sessions \$ROOT/data/logs \$ROOT/uploads \$ROOT/favicons
sudo chown -R www-data:www-data \$ROOT/data \$ROOT/uploads \$ROOT/favicons
sudo chmod -R 770 \$ROOT/data
cd \$ROOT
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction
sudo -u www-data php scripts/migrate.php
echo "Deploy complete."
REMOTE

echo "==> Deploy done."
echo "First-time admin setup: visit https://your-domain/install, or run:"
echo "  ssh $TYLIO_SSH_TARGET 'cd $TYLIO_REMOTE_ROOT && sudo -u www-data php scripts/seed.php --username=admin --password=...'"
