#!/usr/bin/env bash
#
# Deterministic build. The same source tree must produce byte-identical output
# on any machine, because scripts/verify.sh asks a third party to reproduce
# these exact bytes and compare them against what note.my serves.
#
# Rules that keep it deterministic:
#   - every tool version is pinned in frontend/package.json, installed with
#     `npm ci` against the committed lockfile
#   - no timestamps, build IDs, absolute paths or randomness enters the output
#   - no sourcemap is emitted; one would embed local filesystem paths
#   - the content hash is computed from the built bytes, so the filename is a
#     function of the source alone
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FRONTEND="$ROOT/frontend"
OUT="$ROOT/public/assets"
BIN="$FRONTEND/node_modules/.bin"

# Strip anything that could leak the build host into output.
export SOURCE_DATE_EPOCH=0
export TZ=UTC
export LC_ALL=C
export NODE_ENV=production

if [ ! -x "$BIN/esbuild" ]; then
  echo "error: run 'npm ci' in frontend/ first" >&2
  exit 1
fi

echo "==> typecheck"
(cd "$FRONTEND" && "$BIN/tsc" --noEmit)

rm -rf "$OUT"
mkdir -p "$OUT/fonts"

echo "==> css"
(cd "$FRONTEND" && "$BIN/tailwindcss" \
  --input src/styles.css \
  --output "$OUT/app.css" \
  --minify) 2>/dev/null

echo "==> js"
(cd "$FRONTEND" && "$BIN/esbuild" src/main.ts \
  --bundle \
  --format=iife \
  --target=es2022 \
  --minify \
  --legal-comments=none \
  --outfile="$OUT/app.js" \
  --log-level=warning)

# Content-addressed filenames. 16 hex chars is ample for cache busting and
# keeps the SRI attribute the authoritative integrity check.
hash_of() { sha256sum "$1" | cut -c1-16; }

JS_HASH="$(hash_of "$OUT/app.js")"
CSS_HASH="$(hash_of "$OUT/app.css")"
JS_NAME="app.${JS_HASH}.js"
CSS_NAME="app.${CSS_HASH}.css"
mv "$OUT/app.js" "$OUT/$JS_NAME"
mv "$OUT/app.css" "$OUT/$CSS_NAME"

sri_of() { echo "sha384-$(openssl dgst -sha384 -binary "$1" | openssl base64 -A)"; }

JS_SRI="$(sri_of "$OUT/$JS_NAME")"
CSS_SRI="$(sri_of "$OUT/$CSS_NAME")"

# Fonts are committed to the repo, never fetched from Google. Copying rather
# than symlinking keeps the output tree self-contained.
if [ -d "$FRONTEND/fonts" ]; then
  cp -f "$FRONTEND"/fonts/*.woff2 "$OUT/fonts/" 2>/dev/null || true
fi

# PageController reads this. Written sorted and without a timestamp so the
# manifest itself is reproducible.
cat > "$OUT/manifest.json" <<JSON
{
  "css": "$CSS_NAME",
  "cssSri": "$CSS_SRI",
  "js": "$JS_NAME",
  "jsSri": "$JS_SRI"
}
JSON

echo
echo "  $JS_NAME"
echo "    $JS_SRI"
echo "  $CSS_NAME"
echo "    $CSS_SRI"
echo
echo "Point config.php 'assets' at manifest.json, or let index.php read it directly."
