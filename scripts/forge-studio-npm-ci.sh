#!/usr/bin/env bash
# Run from the Laravel app root on the server (same directory as artisan).
# Intended for Laravel Forge / Envoyer "Deploy" hooks after `git pull` / `composer install`.
#
#   cd "$FORGE_SITE_PATH" && bash scripts/forge-studio-npm-ci.sh
#
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [[ ! -f package.json ]]; then
  echo "forge-studio-npm-ci: no package.json in $ROOT — skip."
  exit 0
fi

echo "forge-studio-npm-ci: npm ci in $ROOT"
npm ci --no-audit --no-fund

if [[ "${PLAYWRIGHT_BROWSERS_PATH:-}" == "0" ]]; then
  echo "forge-studio-npm-ci: PLAYWRIGHT_BROWSERS_PATH=0 → npx playwright install chromium"
  PLAYWRIGHT_BROWSERS_PATH=0 npx playwright install chromium
else
  echo "forge-studio-npm-ci: npx playwright install --with-deps chromium"
  npx playwright install --with-deps chromium
fi

echo "forge-studio-npm-ci: done."
