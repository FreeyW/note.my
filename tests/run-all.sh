#!/usr/bin/env bash
# Runs every suite. Needs MariaDB, Redis and a built frontend.
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ESBUILD="$ROOT/frontend/node_modules/.bin/esbuild"
PORT="${NOTEMY_PORT:-8080}"
failed=0

step() { echo; echo "### $1"; }

step "typecheck"
(cd "$ROOT/frontend" && node_modules/.bin/tsc --noEmit) || failed=1

step "php lint"
for f in $(find "$ROOT/src" "$ROOT/scripts" "$ROOT/public" -name '*.php'); do
  php -l "$f" | grep -v "No syntax errors" && failed=1
done
echo "ok"

step "crypto (node webcrypto)"
"$ESBUILD" "$ROOT/tests/crypto.test.ts" --bundle --platform=node --format=esm \
  --outfile=/tmp/nm-crypto.mjs --log-level=warning && node /tmp/nm-crypto.mjs || failed=1

step "store"
php "$ROOT/tests/NoteStoreTest.php" || failed=1

step "concurrency"
"$ROOT/tests/race.sh" 10 16 "READ COMMITTED" || failed=1

php -S "127.0.0.1:$PORT" -t "$ROOT/public" "$ROOT/public/index.php" > /tmp/nm-srv.log 2>&1 &
SRV=$!
sleep 2

step "http api"
php "$ROOT/tests/HttpTest.php" || failed=1

step "seo"
php "$ROOT/tests/SeoTest.php" || failed=1

step "full stack"
"$ESBUILD" "$ROOT/tests/e2e.test.ts" --bundle --platform=node --format=esm \
  --outfile=/tmp/nm-e2e.mjs --log-level=warning && node /tmp/nm-e2e.mjs || failed=1

kill $SRV 2>/dev/null

echo
[ "$failed" -eq 0 ] && echo "ALL SUITES PASSED" || echo "SOME SUITES FAILED"
exit $failed
