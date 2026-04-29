#!/usr/bin/env bash
# Vite (5173) + PHP built-in (8080) para /api/* — o mesmo alvo de vite.config.mjs → proxy
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
if ! command -v php >/dev/null 2>&1; then
  echo "php: comando não encontrado. Instala PHP 8+ com pdo_sqlite (ex.: apt install php-cli php-sqlite3)." >&2
  exit 1
fi
if [[ ! -f "$ROOT/node_modules/.bin/vite" ]]; then
  echo "Executa «npm install» na raiz do repositório primeiro." >&2
  exit 1
fi
php -S 127.0.0.1:8080 -t server &
PHP_PID=$!
cleanup() {
  kill "$PHP_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM
# shellcheck disable=SC2016
echo 'PHP: http://127.0.0.1:8080  |  Vite: http://127.0.0.1:5173  |  Copia server/api/config.example.php → server/api/config.php se ainda não tiveres.'
exec "$ROOT/node_modules/.bin/vite"
