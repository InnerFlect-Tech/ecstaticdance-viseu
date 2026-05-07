#!/bin/sh
# Coolify / Docker: config + dirs, PHP (:8080) em background + Nginx em primeiro plano na :80.
# O proxy público deve mapear para a porta exposta pelo contentor (**80**, não 8080).
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

# API/admin/upload → proxy nginx → 127.0.0.1:8080; HTML estático sirvido pela própria nginx na :80.
/usr/bin/php -S 127.0.0.1:8080 -t "${SERVER_ROOT}" &
exec /usr/sbin/nginx -g "daemon off;"
