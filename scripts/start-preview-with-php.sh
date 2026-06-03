#!/usr/bin/env bash
# Vite preview (Coolify / Nixpacks) + PHP built-in para /api, /admin, /uploads (proxy em vite.config.mjs).
# Sem isto, o proxy aponta para 127.0.0.1:8080 e falha com ECONNREFUSED.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

export BROWSER=none
export CI="${CI:-true}"

if ! command -v php >/dev/null 2>&1; then
  echo "Erro: «php» não está no PATH. Adiciona PHP ao Nixpacks (aptPkgs em nixpacks.toml)." >&2
  exit 1
fi

if [[ ! -f "$ROOT/server/api/config.php" ]]; then
  cp "$ROOT/server/api/config.example.php" "$ROOT/server/api/config.php"
  echo "Aviso: criado server/api/config.php a partir do exemplo — define credenciais MySQL em produção." >&2
fi

COMMIT="${SOURCE_COMMIT:-${COOLIFY_COMMIT_SHA:-unknown}}"
STAMP=$(printf '{"commit":"%s","built_at":"%s","stack":"nixpacks-preview"}\n' "$COMMIT" "$(date -u +%Y-%m-%dT%H:%M:%SZ)")
printf '%s' "$STAMP" > "$ROOT/server/api/build-info.json"
if [[ -d "$ROOT/dist" ]]; then
  printf '%s' "$STAMP" > "$ROOT/dist/deploy-stamp.json"
fi

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
  echo "Erro: o servidor PHP (127.0.0.1:${PHP_PORT}) não arrancou." >&2
  exit 1
fi

export EDV_PHP_API_PORT="$PHP_PORT"

PORT="${PORT:-4173}"
exec "$ROOT/node_modules/.bin/vite" preview --host 0.0.0.0 --port "$PORT"
