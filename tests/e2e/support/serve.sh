#!/usr/bin/env bash
# Serves the application for the browser E2E suite and keeps it served.
#
# Why this is not `php artisan serve`:
#  - artisan serve ignores PHP_CLI_SERVER_WORKERS unless --no-reload is given,
#    so the old "4 workers" setting was never in effect;
#  - it runs php -S as a child and parses its output line by line; when that
#    child dies (a PHP crash, a signal) it exits too — silently, with its
#    output thrown away — and every later request is refused;
#  - it restarts the server whenever .env changes.
# Here php -S runs directly, inside a loop: if it ever dies the exit status is
# written to the log and a new one starts within a fraction of a second, so one
# bad request can no longer take the rest of the suite down with it.
#
# Everything the server prints (request lines, PHP errors) goes to
# storage/logs/e2e-server.log, which the CI job uploads with the Laravel log.
#
# Usage: bash tests/e2e/support/serve.sh [port]
#   E2E_SERVER_WORKERS  php -S worker processes (default 1; PHP calls >1 experimental)
#   E2E_SERVER_MEMORY   memory_limit for the server (default 1G)

set -u

port="${1:-${E2E_PORT:-8000}}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
router="$root/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
log="$root/storage/logs/e2e-server.log"

mkdir -p "$root/storage/logs"
export PHP_CLI_SERVER_WORKERS="${E2E_SERVER_WORKERS:-1}"

# The router script resolves static files against the working directory.
cd "$root/public" || exit 1

while true; do
  echo "[$(date -u +%FT%TZ)] e2e-server: starting php -S 127.0.0.1:$port (workers=$PHP_CLI_SERVER_WORKERS)" >> "$log"

  php \
    -d "memory_limit=${E2E_SERVER_MEMORY:-1G}" \
    -d max_execution_time=120 \
    -d log_errors=1 \
    -S "127.0.0.1:$port" "$router" >> "$log" 2>&1
  status=$?

  # 128 + n means the process was killed by signal n (139 = SIGSEGV, 137 = SIGKILL).
  echo "[$(date -u +%FT%TZ)] e2e-server: php -S EXITED with status $status" >> "$log"
  sleep 0.3
done
