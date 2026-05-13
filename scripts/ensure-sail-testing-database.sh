#!/usr/bin/env bash
# Ensure the MySQL database named "testing" exists inside Sail.
# - Runs SHOW DATABASES and CREATE DATABASE IF NOT EXISTS testing only.
# - Does NOT migrate, drop, refresh, or touch any other database (including your dev DB from .env).
#
# Next steps: docs/TESTING_DATABASE.md ("Sail MySQL testing database")
#   ./vendor/bin/sail artisan migrate:fresh --force --env=testing
#   ./vendor/bin/sail phpunit tests/Unit/Services/Preview3dVariantPathResolverTest.php tests/Unit/Support/Preview3dDeliveryUrlsTest.php
set -euo pipefail
cd "$(dirname "$0")/.."

echo "=== Sail MySQL: databases (confirm 'testing' is listed) ==="
./vendor/bin/sail mysql -u sail -ppassword -e "SHOW DATABASES;"

echo "=== CREATE DATABASE IF NOT EXISTS testing (no other DBs modified) ==="
./vendor/bin/sail mysql -u sail -ppassword -e "CREATE DATABASE IF NOT EXISTS testing;"

echo "=== Done. For migrations on testing only, run: ./vendor/bin/sail artisan migrate:fresh --force --env=testing ==="
