#!/usr/bin/env bash
set -e

############################################
# Configuration
############################################

APP_DIR="${APP_DIR:-/var/www/jackpot}"
RELEASES_DIR="$APP_DIR/releases"
SHARED_DIR="$APP_DIR/shared"
CURRENT_LINK="$APP_DIR/current"

GIT_REF="${GIT_REF:-master}"
RELEASE_ID="$(date +%Y%m%d-%H%M%S)-${GITHUB_SHA:-manual}"
RELEASE_PATH="$RELEASES_DIR/$RELEASE_ID"

LOCKFILE="$APP_DIR/deploy.lock"

############################################
# Deploy lock (prevents concurrent deploys)
############################################

exec 9>"$LOCKFILE"
if ! flock -n 9; then
  echo "❌ Deploy already running. Exiting."
  exit 1
fi

############################################
# Start deploy
############################################

echo "🚀 Starting deploy"
echo "• Release: $RELEASE_ID"
echo "• Git ref: $GIT_REF"
echo "• Path:    $RELEASE_PATH"
echo

############################################
# Prepare release
############################################

mkdir -p "$RELEASE_PATH"

echo "📦 Cloning repository"
git clone --depth=1 --branch "$GIT_REF" \
  git@github.com:ilovetoast/jackpot-firehorse.git "$RELEASE_PATH"

cd "$RELEASE_PATH"

############################################
# Shared directories & symlinks
############################################

echo "🔗 Linking shared files"

mkdir -p \
  "$SHARED_DIR/storage" \
  "$SHARED_DIR/bootstrap/cache" \
  "$SHARED_DIR/storage/framework/cache" \
  "$SHARED_DIR/storage/framework/views" \
  "$SHARED_DIR/storage/framework/sessions"

ln -sfn "$SHARED_DIR/.env" .env

rm -rf storage
ln -sfn "$SHARED_DIR/storage" storage

rm -rf bootstrap/cache
ln -sfn "$SHARED_DIR/bootstrap/cache" bootstrap/cache

chmod -R ug+rw storage bootstrap/cache

############################################
# Dependencies
############################################

echo "📚 Installing Composer dependencies"
composer install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --optimize-autoloader

############################################
# Laravel steps
############################################

echo "🧱 Running database migrations"
php artisan migrate --force

echo "🌱 Running database seeders"
php artisan db:seed --force

echo "⚡ Optimizing Laravel"
php artisan optimize


############################################
# Health check (lightweight)
############################################

echo "🩺 Running sanity check"
php artisan about >/dev/null

############################################
# Atomic release switch
############################################

echo "🔁 Switching current release"
ln -sfn "$RELEASE_PATH" "$CURRENT_LINK"

############################################
# Done
############################################

echo
echo "✅ Deploy complete!"
echo "• Active release: $RELEASE_ID"
