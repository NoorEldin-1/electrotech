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
#   E2E_OPCACHE=1       keep opcache on (it is off by default, see below)
#
# opcache and the JIT are switched off for the server on purpose. The first CI
# run with this script logged `php -S EXITED with status 139` (SIGSEGV) eight
# times in three minutes, on unrelated pages (customer-interactions,
# general-ledger-report, projects, journal-entries, several /livewire/update
# posts), and just before the first crash Laravel logged "Undefined variable
# $getFiltersTriggerAction" in a vendor Filament view that defines it a few
# lines earlier. Random pages, a variable that cannot be undefined: engine-level
# memory corruption, not a recursion in the application. shivammathur/setup-php
# enables the tracing JIT (256M buffer) by default; the PHPUnit job survives it,
# a long-running server does not. Speed is irrelevant here (a screen opens in a
# few hundred ms either way), so everything that rewrites compiled code is off.

set -u

port="${1:-${E2E_PORT:-8000}}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
router="$root/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
log="$root/storage/logs/e2e-server.log"

mkdir -p "$root/storage/logs"
workers="${E2E_SERVER_WORKERS:-1}"
if [ "$workers" -gt 1 ]; then
  export PHP_CLI_SERVER_WORKERS="$workers"
else
  # php -S prints "number of workers must be larger than 1" for a value of 1.
  unset PHP_CLI_SERVER_WORKERS
fi

engine_flags=(-d opcache.jit=disable -d opcache.jit_buffer_size=0)
if [ "${E2E_OPCACHE:-0}" != "1" ]; then
  engine_flags+=(-d opcache.enable=0 -d opcache.enable_cli=0)
fi

# The router script resolves static files against the working directory.
cd "$root/public" || exit 1

while true; do
  echo "[$(date -u +%FT%TZ)] e2e-server: starting php -S 127.0.0.1:$port (workers=$workers, opcache=${E2E_OPCACHE:-0})" >> "$log"

  php \
    "${engine_flags[@]}" \
    -d "memory_limit=${E2E_SERVER_MEMORY:-1G}" \
    -d max_execution_time=120 \
    -d log_errors=1 \
    -S "127.0.0.1:$port" "$router" >> "$log" 2>&1
  status=$?

  # 128 + n means the process was killed by signal n (139 = SIGSEGV, 137 = SIGKILL).
  echo "[$(date -u +%FT%TZ)] e2e-server: php -S EXITED with status $status" >> "$log"
  sleep 0.3
done
