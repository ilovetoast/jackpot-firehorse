#!/usr/bin/env bash
# Jackpot maintenance-mode wrapper for on-server use.
#
#   ./bin/maintenance.sh on       # enable branded maintenance splash
#   ./bin/maintenance.sh off      # bring the site back
#   ./bin/maintenance.sh status   # show current state + bypass hint
#
# Thin wrapper around `php artisan jackpot:maintenance` so the command works
# regardless of the operator's current working directory when SSH'd into the
# box. See docs/operations/MAINTENANCE_MODE.md for full runbook.

set -euo pipefail

# Resolve the Laravel app root (this script lives in <root>/bin).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

if [ ! -f "${APP_ROOT}/artisan" ]; then
  echo "error: could not locate artisan at ${APP_ROOT}/artisan" >&2
  exit 1
fi

ACTION="${1:-status}"

case "${ACTION}" in
  on|off|status|enable|disable|up|down|state)
    cd "${APP_ROOT}"
    exec php artisan jackpot:maintenance "${ACTION}" "${@:2}"
    ;;
  -h|--help|help|"")
    cat <<'USAGE'
Usage: ./bin/maintenance.sh <on|off|status>

  on       Enable branded maintenance mode. Prints a bypass URL that lets
           your browser through — visit it once to set the cookie, then
           browse the site as normal while maintenance is active.

  off      Disable maintenance mode.

  status   Show whether maintenance is currently on or off.

Docs:  docs/operations/MAINTENANCE_MODE.md
USAGE
    exit 0
    ;;
  *)
    echo "error: unknown action '${ACTION}'. Try: on | off | status" >&2
    exit 1
    ;;
esac
