#!/usr/bin/env bash
# Membuka tunnel ngrok KHUSUS ke webhook Kasera (port 8081). Hentikan dengan Ctrl+C.
# Pemakaian:  bash dev/start-webhook-tunnel.sh
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-/opt/lampp/bin/php}"
PORT="${PORT:-8081}"

"$PHP_BIN" -S "127.0.0.1:$PORT" -t "$ROOT" "$ROOT/dev/webhook-router.php" >/dev/null 2>&1 &
PHP_PID=$!
trap 'kill $PHP_PID 2>/dev/null' EXIT

echo "Endpoint webhook = <URL https yang muncul di bawah>/kasera-webhook"
ngrok http "$PORT"
