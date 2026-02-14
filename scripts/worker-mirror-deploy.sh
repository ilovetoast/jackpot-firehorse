#!/usr/bin/env bash
set -Eeuo pipefail

############################################
# CONFIG
############################################

APP_DIR="/var/www/jackpot"
RELEASES_DIR="$APP_DIR/releases"
SHARED_DIR="$APP_DIR/shared"
CURRENT_LINK="$APP_DIR/current"

REPO_SSH="git@github.com:ilovetoast/jackpot-firehorse.git"
GIT_REF="${GIT_REF:-master}"

PHP="/usr/bin/php"
COMPOSER="/usr/local/bin/composer"
APP_USER="ubuntu"

LOG_FILE="$APP_DIR/deploy/deploy.log"

############################################
# SAFETY: ENSURE KNOWN WORKING DIR
############################################

cd "$APP_DIR"

############################################
# LOGGING
############################################

exec > >(tee -a "$LOG_FILE") 2>&1

echo "========================================"
echo "🚀 WORKER DEPLOY START"
echo "Time:   $(date -u)"
echo "User:   $(whoami)"
echo "Ref:    $GIT_REF"
echo "PWD:    $(pwd)"
echo "========================================"

############################################
# PRE-FLIGHT CHECKS
############################################

command -v git >/dev/null || { echo "❌ git missing"; exit 1; }
command -v php >/dev/null || { echo "❌ php missing"; exit 1; }
command -v composer >/dev/null || { echo "❌ composer missing"; exit 1; }

if [ ! -f "$SHARED_DIR/.env" ]; then
  echo "❌ Missing shared .env at $SHARED_DIR/.env"
  exit 1
fi

if ! grep -q "^APP_KEY=base64:" "$SHARED_DIR/.env"; then
  echo "❌ APP_KEY missing or invalid in shared .env"
  exit 1
fi

############################################
# RELEASE METADATA
############################################

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
RELEASE_DIR="$RELEASES_DIR/$TIMESTAMP"

############################################
# CREATE RELEASE (CLEAN GIT STATE)
############################################

mkdir -p "$RELEASES_DIR"
chown -R "$APP_USER:$APP_USER" "$RELEASES_DIR"

echo "📥 Cloning repository → $RELEASE_DIR"
sudo -u "$APP_USER" git clone "$REPO_SSH" "$RELEASE_DIR"

cd "$RELEASE_DIR"


sudo -u "$APP_USER" git checkout "$GIT_REF"
sudo -u "$APP_USER" git reset --hard "origin/$GIT_REF"

COMMIT_SHA="$(git rev-parse HEAD)"
COMMIT_MSG="$(git log -1 --pretty=%B | tr -d '\n')"
COMMIT_AUTHOR="$(git log -1 --pretty=%an)"

echo "🚀 Deploying commit: $(git rev-parse --short HEAD)"
echo "🧠 Message: $COMMIT_MSG"

############################################
# ENV + STORAGE
############################################

ln -sfn "$SHARED_DIR/.env" .env

rm -rf storage bootstrap/cache
ln -sfn "$SHARED_DIR/storage" storage
mkdir -p bootstrap/cache

############################################
# DEPENDENCIES
############################################

echo "📦 Installing PHP dependencies"
sudo -u "$APP_USER" "$COMPOSER" install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --optimize-autoloader

############################################
# LARAVEL
############################################

echo "⚙️ Running migrations"
sudo -u "$APP_USER" "$PHP" artisan migrate --force

echo "⚙️ Optimizing"
#sudo -u "$APP_USER" "$PHP" artisan optimize

############################################
# DEPLOY METADATA
############################################

cat <<EOF > "$RELEASE_DIR/DEPLOYED_AT"
Deployed at:  $(date -u)
Release dir:  $RELEASE_DIR
Git ref:      $GIT_REF
Commit:       $COMMIT_SHA
Author:       $COMMIT_AUTHOR
Message:      $COMMIT_MSG
User:         $(whoami)
EOF

############################################
# ATOMIC SWITCH
############################################

ln -sfn "$RELEASE_DIR" "$CURRENT_LINK"
echo "🔁 current → $RELEASE_DIR"

############################################
# GRACEFUL WORKER RESTART (NEW CODE)
############################################

if command -v php >/dev/null && php artisan list | grep -q horizon; then
  echo "♻️ Gracefully restarting Horizon"
  php artisan horizon:terminate || true
fi


############################################
# OPTIONAL: RESTART SUPERVISED WORKERS
############################################

if [ -d /etc/supervisor/conf.d ]; then
  echo "ℹ️ Supervisor installed but no managed workers yet"
fi


############################################
# CLEANUP (KEEP 5)
############################################

ls -dt "$RELEASES_DIR"/* | tail -n +6 | xargs -r rm -rf

############################################
# DONE
############################################

echo "✅ WORKER DEPLOY COMPLETE"
echo "========================================"
