#!/usr/bin/env bash
# Vite preview (Coolify / Nixpacks) + PHP built-in para /api, /admin, /uploads (proxy em vite.config.mjs).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

export BROWSER=none
export CI="${CI:-true}"

SERVER_ROOT="${EDV_SERVER_ROOT:-}"
if [[ -n "$SERVER_ROOT" ]]; then
  mkdir -p "${SERVER_ROOT}/data" "${SERVER_ROOT}/uploads/link-proofs"
  chmod 775 "${SERVER_ROOT}/data" "${SERVER_ROOT}/uploads" "${SERVER_ROOT}/uploads/link-proofs" 2>/dev/null || true
  # Volume Coolify em /var/www/edv-server/uploads; PHP (document root /app/server) usa server/uploads
  if [[ -d "${SERVER_ROOT}/uploads" || -d "${SERVER_ROOT}/data" ]]; then
    rm -rf "$ROOT/server/uploads"
    ln -sfn "${SERVER_ROOT}/uploads" "$ROOT/server/uploads"
  fi
else
  mkdir -p "$ROOT/server/data" "$ROOT/server/uploads/link-proofs"
  chmod 775 "$ROOT/server/data" "$ROOT/server/uploads" "$ROOT/server/uploads/link-proofs" 2>/dev/null || true
fi

if ! command -v php >/dev/null 2>&1; then
  echo "Erro: «php» não está no PATH. Ver nixpacks.toml (php83)." >&2
  exit 1
fi

if [[ "${EDV_REPLACE_CONFIG_FROM_EXAMPLE:-0}" == "1" ]]; then
  cp "$ROOT/server/api/config.example.php" "$ROOT/server/api/config.php"
elif [[ ! -f "$ROOT/server/api/config.php" ]]; then
  cp "$ROOT/server/api/config.example.php" "$ROOT/server/api/config.php"
  echo "Aviso: criado server/api/config.php a partir do exemplo." >&2
fi

COMMIT="${SOURCE_COMMIT:-${COOLIFY_COMMIT_SHA:-unknown}}"
STAMP=$(printf '{"commit":"%s","built_at":"%s","stack":"nixpacks-vite-preview"}\n' "$COMMIT" "$(date -u +%Y-%m-%dT%H:%M:%SZ)")
printf '%s' "$STAMP" > "$ROOT/server/api/build-info.json"
if [[ -d "$ROOT/dist" ]]; then
  printf '%s' "$STAMP" > "$ROOT/dist/deploy-stamp.json"
fi

if [[ ! -f "$ROOT/dist/links.html" ]]; then
  echo "FATAL: dist/links.html em falta. O build Nixpacks falhou ou um volume montou em /app/dist e apagou o build." >&2
  exit 1
fi

LISTEN_PORT="${PORT:-3000}"
MANUAL_JS=$(grep -oE 'manual-booking-[A-Za-z0-9_-]+\.js' "$ROOT/dist/links.html" | head -1 || true)

echo "═══════════════════════════════════════════════════════════"
echo " EDV start | stack=nixpacks-vite-preview | commit=${COMMIT}"
echo " listen=0.0.0.0:${LISTEN_PORT} | php→127.0.0.1:\${EDV_PHP_API_PORT}"
echo " data=${SERVER_ROOT:-$ROOT/server}/data (SQLite nos volumes Coolify)"
echo " dist/links.html → ${MANUAL_JS:-?}"
echo "═══════════════════════════════════════════════════════════"

port_busy() {
  timeout 1 bash -c "</dev/tcp/127.0.0.1/$1" >/dev/null 2>&1
}

if [[ -n "${EDV_PHP_API_PORT:-}" ]]; then
  PHP_PORT="$EDV_PHP_API_PORT"
  if port_busy "$PHP_PORT"; then
    echo "Erro: EDV_PHP_API_PORT=$PHP_PORT já está ocupada." >&2
    exit 1
  fi
else
  PHP_PORT=""
  for p in $(seq 8080 8099); do
    if ! port_busy "$p"; then
      PHP_PORT=$p
      break
    fi
  done
  if [[ -z "$PHP_PORT" ]]; then
    echo "Erro: nenhuma porta livre entre 8080 e 8099." >&2
    exit 1
  fi
fi

php -S "127.0.0.1:${PHP_PORT}" -t "$ROOT/server" &
PHP_PID=$!
cleanup() {
  kill "$PHP_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

sleep 0.3
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "Erro: PHP (127.0.0.1:${PHP_PORT}) não arrancou." >&2
  exit 1
fi

export EDV_PHP_API_PORT="$PHP_PORT"
echo " PHP OK em 127.0.0.1:${PHP_PORT}"

exec "$ROOT/node_modules/.bin/vite" preview --host 0.0.0.0 --port "$LISTEN_PORT"
