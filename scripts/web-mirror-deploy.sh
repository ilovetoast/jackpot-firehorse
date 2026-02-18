#!/usr/bin/env bash
set -Eeuo pipefail

############################################
# HARD REQUIREMENT FOR SSM
############################################
cd /var/www/jackpot

############################################
# CONFIG
############################################

APP_DIR="/var/www/jackpot"
RELEASES_DIR="$APP_DIR/releases"
SHARED_DIR="$APP_DIR/shared"
CURRENT_LINK="$APP_DIR/current"
DEPLOY_DIR="$APP_DIR/deploy"

REPO_SSH="git@github.com:ilovetoast/jackpot-firehorse.git"
GIT_REF="${GIT_REF:-master}"

NODE_REQUIRED_MAJOR="20"

############################################
# SERVER ROLE GUARD (WEB ONLY)
############################################

#if [[ "${SERVER_ROLE:-}" != "web" ]]; then
#  echo "❌ This deploy script may only run on WEB servers"
#  echo "SERVER_ROLE=${SERVER_ROLE:-unset}"
#  exit 1
#fi

############################################
# Release counter (derived, no state)
############################################

LAST_NUM=$(ls -1 "$RELEASES_DIR" 2>/dev/null \
  | sed -n 's/.*-r\([0-9]\{3\}\).*/\1/p' \
  | sort -n \
  | tail -1)

LAST_NUM=${LAST_NUM:-0}
NEXT_RELEASE_NUM=$(printf "%03d" $((10#$LAST_NUM + 1)))

RELEASE_ID="$(date +%Y%m%d-%H%M%S)-r${NEXT_RELEASE_NUM}-${GITHUB_SHA:-manual}"
RELEASE_PATH="$RELEASES_DIR/$RELEASE_ID"

LOG_FILE="$DEPLOY_DIR/deploy.log"

############################################
# FLAGS
############################################

RUN_SEED=false
RUN_BUILD=true
DRY_RUN=false
ROLLBACK=false

for arg in "$@"; do
  case "$arg" in
    --seed) RUN_SEED=true ;;
    --no-build) RUN_BUILD=false ;;
    --dry-run) DRY_RUN=true ;;
    --rollback) ROLLBACK=true ;;
  esac
done

############################################
# LOGGING
############################################

exec > >(tee -a "$LOG_FILE") 2>&1

echo "========================================"
echo "🚀 DEPLOY START"
echo "Time:    $(date -u)"
echo "User:    $(whoami)"
echo "Release: $RELEASE_ID"
echo "Flags:   seed=$RUN_SEED build=$RUN_BUILD dry=$DRY_RUN rollback=$ROLLBACK"
echo "PWD:     $(pwd)"
echo "========================================"

############################################
# LOCK
############################################
LOCKFILE="$APP_DIR/deploy.lock"
exec 9>"$LOCKFILE"
flock -n 9 || { echo "❌ Deploy already running"; exit 1; }

############################################
# ROLLBACK (revert to previous release)
############################################

if [[ "$ROLLBACK" == true ]]; then
  echo "⏪ ROLLBACK: Reverting to previous release"
  PREV=$(ls -dt "$RELEASES_DIR"/* 2>/dev/null | sed -n '2p')
  if [[ -z "$PREV" || ! -d "$PREV" ]]; then
    echo "❌ No previous release found to rollback to"
    exit 1
  fi
  ln -sfn "$PREV" "$CURRENT_LINK"
  echo "🔁 current → $(basename "$PREV")"
  sudo systemctl reload nginx
  sudo systemctl restart php8.4-fpm
  echo "✅ ROLLBACK COMPLETE"
  echo "========================================"
  exit 0
fi

############################################
# NODE + NPM
############################################

if ! command -v node >/dev/null; then
  echo "📦 Installing Node.js"
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
  apt-get install -y nodejs
fi

INSTALLED_NODE="$(node -v | sed 's/^v//')"

if [[ ! "$INSTALLED_NODE" =~ ^${NODE_REQUIRED_MAJOR}\. ]]; then
  echo "❌ Node ${NODE_REQUIRED_MAJOR}.x required (found v$INSTALLED_NODE)"
  exit 1
fi

echo "✅ Node v$INSTALLED_NODE"
echo "✅ npm $(npm -v)"

############################################
# PREP DIRECTORIES
############################################

mkdir -p "$RELEASES_DIR" "$SHARED_DIR"

############################################
# CLONE
############################################

echo "📥 Cloning repository → $RELEASE_PATH"
git clone --depth=1 --branch "$GIT_REF" "$REPO_SSH" "$RELEASE_PATH"

cd "$RELEASE_PATH"
echo "📂 Now in $(pwd)"

############################################
# COMMIT METADATA
############################################

COMMIT_SHA="$(git rev-parse HEAD)"
COMMIT_SHORT="$(git rev-parse --short HEAD)"
COMMIT_MSG="$(git log -1 --pretty=%B | tr -d '\n')"
COMMIT_AUTHOR="$(git log -1 --pretty='%an')"

echo "🔖 Commit: $COMMIT_SHORT"

############################################
# SHARED FILES
############################################

ln -sfn "$SHARED_DIR/.env" .env

rm -rf storage bootstrap/cache
ln -sfn "$SHARED_DIR/storage" storage
ln -sfn "$SHARED_DIR/bootstrap/cache" bootstrap/cache

############################################
# PHP DEPENDENCIES
############################################

echo "📦 Installing PHP dependencies"
composer install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --optimize-autoloader

############################################
# DATABASE (WEB OWNS SCHEMA)
############################################

echo "🗄   Running database migrations (web only)"
php artisan migrate --force

if [[ "$RUN_SEED" == true ]]; then
  echo "🌱 Running seeders"
  php artisan db:seed --force
else
  echo "⏭   Skipping seeders"
fi

############################################
# CACHE REBUILD (WEB)
############################################
php artisan optimize:clear
php artisan optimize

############################################
# FRONTEND BUILD
############################################

if [[ "$RUN_BUILD" == true ]]; then
  echo "🎨 Building frontend"
  npm ci --no-audit --no-fund
  npm run build
else
  echo "⏭   Skipping frontend build"
fi

############################################
# VERIFY BUILD
############################################

if [[ ! -f public/build/manifest.json ]]; then
  echo "❌ Vite manifest missing"
  exit 1
fi

############################################
# PREFLIGHT CHECKS
############################################

echo "🔍 Running preflight checks..."

[[ -f public/index.php ]] || { echo "❌ public/index.php missing"; exit 1; }
[[ -f .env ]] || { echo "❌ .env missing"; exit 1; }

php artisan --version >/dev/null || {
  echo "❌ artisan not runnable"
  exit 1
}

echo "✅ Preflight checks passed"

############################################
# DEPLOY METADATA
############################################

cat <<EOF > "$RELEASE_PATH/DEPLOYED_AT"
Deployed at:  $(date -u)
Release:      $RELEASE_ID
Git ref:      $GIT_REF
Commit:       $COMMIT_SHA
Author:       $COMMIT_AUTHOR
Message:      $COMMIT_MSG
User:         $(whoami)
Seeded:       $RUN_SEED
Built:        $RUN_BUILD
EOF

############################################
# SWITCH
############################################

if [[ "$DRY_RUN" == false ]]; then
  ln -sfn "$RELEASE_PATH" "$CURRENT_LINK"
  echo "🔁 current → $RELEASE_ID ($COMMIT_SHORT)"
else
  echo "🧪 Dry run — current not updated"
fi

sudo systemctl reload nginx
sudo systemctl restart php8.4-fpm

############################################
# CLEANUP (KEEP 5)
############################################

ls -dt "$RELEASES_DIR"/* | tail -n +6 | xargs -r rm -rf

############################################
# DONE
############################################

echo "✅ DEPLOY COMPLETE"
echo "========================================"
