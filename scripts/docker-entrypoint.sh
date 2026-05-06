#!/bin/sh
# Coolify / Docker: ensure config + data dir, then supervisord (PHP + Nginx).
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

exec /usr/bin/supervisord -c /etc/supervisord.conf
