#!/usr/bin/env bash
# Verifica se ecstaticdanceviseu.pt serve o deploy Nixpacks recente (correr após redeploy).
set -euo pipefail
BASE="${1:-https://ecstaticdanceviseu.pt}"
FAIL=0

check() {
  local name="$1"
  local ok="$2"
  if [[ "$ok" == "1" ]]; then
    echo "OK   $name"
  else
    echo "FAIL $name"
    FAIL=1
  fi
}

echo "=== Production deploy check: $BASE ==="

HEALTH=$(curl -fsS "$BASE/api/health.php?diag=1" 2>/dev/null || echo '{}')
check "health.php responde JSON" "$(echo "$HEALTH" | jq -e '.ok == true' >/dev/null 2>&1 && echo 1 || echo 0)"

COMMIT=$(echo "$HEALTH" | jq -r '.commit // empty' 2>/dev/null || true)
if [[ -n "$COMMIT" && "$COMMIT" != "unknown" && "$COMMIT" != "null" ]]; then
  check "health tem commit ($COMMIT)" 1
else
  check "health tem commit (stack antiga se vazio)" 0
fi

STACK=$(echo "$HEALTH" | jq -r '.stack // empty' 2>/dev/null || true)
check "stack=nixpacks-vite-preview" "$([[ "$STACK" == "nixpacks-vite-preview" ]] && echo 1 || echo 0)"

HASH=$(curl -fsS "$BASE/links.html" 2>/dev/null | grep -oE 'manual-booking-[A-Za-z0-9_-]+\.js' | head -1 || true)
if [[ -n "$HASH" && "$HASH" != *DebSsfR_* ]]; then
  check "links.html asset novo ($HASH)" 1
else
  check "links.html asset novo (ainda DebSsfR_ = Maio)" 0
fi

PRICING=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/api/get-ticket-pricing.php?email=test@example.com" 2>/dev/null || echo 000)
check "get-ticket-pricing.php → 200" "$([[ "$PRICING" == "200" ]] && echo 1 || echo 0)"

STAMP_CT=$(curl -sS -o /tmp/edv-stamp.json -w '%{http_code}' "$BASE/deploy-stamp.json" 2>/dev/null || echo 000)
STAMP_OK=0
if [[ "$STAMP_CT" == "200" ]] && jq -e '.commit' /tmp/edv-stamp.json >/dev/null 2>&1; then
  STAMP_OK=1
fi
check "deploy-stamp.json é JSON com commit" "$STAMP_OK"

echo "=== Fim (exit $FAIL) ==="
exit "$FAIL"
