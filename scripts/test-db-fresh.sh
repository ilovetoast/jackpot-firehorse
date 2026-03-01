#!/usr/bin/env bash
# Safe test database refresh — ONLY touches the "testing" database, never the main DB.
# Use this instead of running migrate:fresh directly.
set -e
cd "$(dirname "$0")/.."
echo "Refreshing TEST database only (DB_DATABASE=testing)..."
./vendor/bin/sail exec laravel.test env DB_DATABASE=testing php artisan migrate:fresh --force
echo "Done. Main database unchanged."
