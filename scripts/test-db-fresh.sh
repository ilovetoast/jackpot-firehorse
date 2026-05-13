#!/usr/bin/env bash
# Safe test database refresh — ONLY touches the "testing" database, never the main DB.
# Use this instead of running migrate:fresh directly. (Plain `sail artisan migrate:fresh` without
# DB_DATABASE=testing is blocked by App\Console\Listeners\BlockUnsafeDestructiveDatabaseCommands unless
# ALLOW_DATABASE_DESTRUCTION=true — see docs/TESTING_DATABASE.md.)
#
# Sail checklist (create DB if missing, never drop your .env DB): docs/TESTING_DATABASE.md
# ("Sail MySQL testing database"). Prefer: ./scripts/ensure-sail-testing-database.sh then this script.
set -e
cd "$(dirname "$0")/.."
echo "Refreshing TEST database only (DB_DATABASE=testing)..."
./vendor/bin/sail exec laravel.test env DB_DATABASE=testing php artisan migrate:fresh --force
echo "Done. Main database unchanged."
