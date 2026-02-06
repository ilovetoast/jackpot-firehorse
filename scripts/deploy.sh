#!/usr/bin/env bash
set -e

APP_DIR="${APP_DIR:-/var/www/jackpot}"
RELEASES_DIR="$APP_DIR/releases"
SHARED_DIR="$APP_DIR/shared"
CURRENT_LINK="$APP_DIR/current"

GIT_REF="${GIT_REF:-master}"
RELEASE_ID="$(date +%Y%m%d-%H%M%S)-${GITHUB_SHA:-manual}"
RELEASE_PATH="$RELEASES_DIR/$RELEASE_ID"

LOCKFILE="$APP_DIR/deploy.lock"
exec 9>"$LOCKFILE"
flock -n 9 || { echo "❌ Deploy already running"; exit 1; }

echo "🚀 Deploying $RELEASE_ID ($GIT_REF)"

mkdir -p "$RELEASE_PATH"

git clone --depth=1 --branch "$GIT_REF" \
  git@github.com:ilovetoast/jackpot-firehorse.git "$RELEASE_PATH"

cd "$RELEASE_PATH"

# Shared directories
mkdir -p \
  "$SHARED_DIR/storage/framework/cache" \
  "$SHARED_DIR/storage/framework/views" \
  "$SHARED_DIR/storage/framework/sessions" \
  "$SHARED_DIR/bootstrap/cache"

ln -sfn "$SHARED_DIR/.env" .env
rm -rf storage
ln -sfn "$SHARED_DIR/storage" storage
rm -rf bootstrap/cache
ln -sfn "$SHARED_DIR/bootstrap/cache" bootstrap/cache
chmod -R ug+rw storage bootstrap/cache

# Dependencies
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# Laravel
php artisan migrate --force
php artisan db:seed --force
php artisan optimize

# Atomic switch
ln -sfn "$RELEASE_PATH" "$CURRENT_LINK"

############################################
# Record deploy metadata (always visible)
############################################

echo "Deployed at: $(date -u '+%Y-%m-%d %H:%M:%S UTC')" > "$CURRENT_LINK/DEPLOYED_AT"
echo "Release:     $RELEASE_ID" >> "$CURRENT_LINK/DEPLOYED_AT"
echo "Git ref:     $GIT_REF" >> "$CURRENT_LINK/DEPLOYED_AT"

echo "✅ Deploy complete: $RELEASE_ID"
