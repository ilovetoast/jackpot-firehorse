#!/usr/bin/env bash
set -e

APP_DIR="${APP_DIR:-/var/www/jackpot}"
RELEASES_DIR="$APP_DIR/releases"
SHARED_DIR="$APP_DIR/shared"
CURRENT_LINK="$APP_DIR/current"

GIT_REF="${GIT_REF:-main}"
RELEASE_ID="$(date +%Y%m%d-%H%M%S)-${GITHUB_SHA:-manual}"
RELEASE_PATH="$RELEASES_DIR/$RELEASE_ID"

echo "🚀 Deploying to $RELEASE_PATH"

mkdir -p "$RELEASE_PATH"
git clone --depth=1 --branch "$GIT_REF" \
  git@github.com:ilovetoast/jackpot-firehorse.git "$RELEASE_PATH"

cd "$RELEASE_PATH"

# Shared links
mkdir -p "$SHARED_DIR/storage" "$SHARED_DIR/bootstrap/cache"

ln -sfn "$SHARED_DIR/.env" .env
rm -rf storage
ln -sfn "$SHARED_DIR/storage" storage
rm -rf bootstrap/cache
ln -sfn "$SHARED_DIR/bootstrap/cache" bootstrap/cache

# Dependencies
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# Laravel
php artisan migrate --force
php artisan optimize

# Atomic switch
ln -sfn "$RELEASE_PATH" "$CURRENT_LINK"

echo "✅ Deploy complete: $RELEASE_ID"
