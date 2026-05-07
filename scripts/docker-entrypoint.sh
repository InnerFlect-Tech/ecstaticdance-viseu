#!/bin/sh
# Coolify / Docker: config + dirs, depois PHP built-in + Nginx (sem Supervisor — evita bug pyexpat/expat no Alpine).
set -eu

SERVER_ROOT="${EDV_SERVER_ROOT:-/var/www/edv-server}"

mkdir -p "${SERVER_ROOT}/data"
mkdir -p "${SERVER_ROOT}/uploads/link-proofs"
chmod 775 "${SERVER_ROOT}/data" 2>/dev/null || true
chmod 775 "${SERVER_ROOT}/uploads" "${SERVER_ROOT}/uploads/link-proofs" 2>/dev/null || true

if [ "${EDV_REPLACE_CONFIG_FROM_EXAMPLE:-}" = "1" ]; then
  cp "${SERVER_ROOT}/api/config.example.php" "${SERVER_ROOT}/api/config.php"
elif [ ! -f "${SERVER_ROOT}/api/config.php" ]; then
  cp "${SERVER_ROOT}/api/config.example.php" "${SERVER_ROOT}/api/config.php"
fi

shutdown() {
  if [ -n "${PHP_PID:-}" ]; then
    kill -TERM "$PHP_PID" 2>/dev/null || true
    wait "$PHP_PID" 2>/dev/null || true
  fi
  if [ -n "${NGINX_PID:-}" ]; then
    kill -TERM "$NGINX_PID" 2>/dev/null || true
    wait "$NGINX_PID" 2>/dev/null || true
  fi
  exit 0
}
trap shutdown TERM INT

/usr/bin/php -S 127.0.0.1:8080 -t "${SERVER_ROOT}" &
PHP_PID=$!

/usr/sbin/nginx -g "daemon off;" &
NGINX_PID=$!

wait "$NGINX_PID"
shutdown
