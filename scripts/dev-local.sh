#!/usr/bin/env bash
# Vite (5173) + PHP built-in (`server/` como docroot para /api/*).
# Escolhemos automaticamente a primeira porta livre em 8080–8099 (evita «Address already in use»).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if ! command -v php >/dev/null 2>&1; then
  echo "Erro: «php» não está instalado ou não está no PATH." >&2
  echo "" >&2
  echo "Instala PHP 8+ com mbstring (+ php-sqlite3 se usares PDO SQLite)." >&2
  echo "" >&2
  echo "Para reservas /links sem SQLite usa LINK_USE_JSON => true em server/api/config.php" >&2
  exit 1
fi

if [[ ! -f "$ROOT/node_modules/.bin/vite" ]]; then
  echo "Executa «npm install» na raiz do repositório primeiro." >&2
  exit 1
fi

if [[ ! -f "$ROOT/server/api/config.php" ]]; then
  cp "$ROOT/server/api/config.example.php" "$ROOT/server/api/config.php"
  echo "Criado server/api/config.php a partir de config.example.php (SQLite em dev; edita LINK_USE_JSON se não tens sqlite)." >&2
fi

# Verdadeiro se já há um serviço a ouvir esta porta no loopback (bash /dev/tcp).
port_busy() {
  timeout 1 bash -c "</dev/tcp/127.0.0.1/$1" >/dev/null 2>&1
}

if [[ -n "${EDV_PHP_API_PORT:-}" ]]; then
  PHP_PORT="$EDV_PHP_API_PORT"
  if port_busy "$PHP_PORT"; then
    echo "Erro: EDV_PHP_API_PORT=$PHP_PORT já está ocupada." >&2
    ss -ltnp "sport = :$PHP_PORT" 2>/dev/null || true >&2
    echo "Liberta a porta ou remove EDV_PHP_API_PORT para escolha automática." >&2
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
    echo "Erro: nenhuma porta livre entre 8080 e 8099 em 127.0.0.1." >&2
    exit 1
  fi
fi

php -r 'exit(class_exists("PDO") && in_array("sqlite", PDO::getAvailableDrivers(), true) ? 0 : 2);' 2>/dev/null || {
  echo "[Aviso] PDO SQLite não disponível. Se save-link-booking falhar, em server/api/config.php define:" >&2
  echo '        LINK_USE_SQLITE=false; LINK_USE_JSON=true;' >&2
}

php -S "127.0.0.1:$PHP_PORT" -t server &
PHP_PID=$!
cleanup() {
  kill "$PHP_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM
sleep 0.25
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "Erro: o servidor PHP não manteve processo vivo (pid $PHP_PID)." >&2
  exit 1
fi

export EDV_PHP_API_PORT="$PHP_PORT"
echo "PHP API: http://127.0.0.1:$PHP_PORT  |  Vite: http://localhost:5173  |  Parar: Ctrl+C"

exec "$ROOT/node_modules/.bin/vite"
