#!/bin/sh
# Coolify / Docker: ensure config + data dir, then supervisord (PHP + Nginx).
set -eu

SERVER_ROOT="${EDV_SERVER_ROOT:-/var/www/edv-server}"

mkdir -p "${SERVER_ROOT}/data"
chmod 775 "${SERVER_ROOT}/data" 2>/dev/null || true

if [ ! -f "${SERVER_ROOT}/api/config.php" ]; then
  cp "${SERVER_ROOT}/api/config.example.php" "${SERVER_ROOT}/api/config.php"
fi

exec /usr/bin/supervisord -c /etc/supervisord.conf
