#!/usr/bin/env bash
#
# Independent verification.
#
# Rebuilds the frontend from this checkout, downloads what the live site is
# actually serving, and compares the two byte for byte. If they match, the
# JavaScript running in your browser is the JavaScript in this repository.
#
#   ./scripts/verify.sh                     # verify https://note.my
#   ./scripts/verify.sh https://note.my     # same, explicit
#   ./scripts/verify.sh http://localhost:8080
#
# What a green result proves: the bundle served to *you*, *now*, was built from
# this source. What it does not prove: that the same bundle is served to
# everyone, always. A compromised server can serve a poisoned bundle to one
# targeted visitor and the honest one to everybody else, including you. That
# limitation is fundamental to any browser-delivered cryptography and is
# spelled out in SECURITY.md.
set -uo pipefail

TARGET="${1:-https://note.my}"
TARGET="${TARGET%/}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
dim()   { printf '\033[2m%s\033[0m\n' "$*"; }

fail=0

echo "note.my build verification"
echo "  target:  $TARGET"
echo "  source:  $ROOT"
if command -v git >/dev/null 2>&1 && git -C "$ROOT" rev-parse --git-dir >/dev/null 2>&1; then
  echo "  commit:  $(git -C "$ROOT" rev-parse --short HEAD)$(git -C "$ROOT" diff --quiet || echo ' (working tree is dirty)')"
fi
echo

# --------------------------------------------------------------------------
echo "==> checking tools"
for tool in node npm curl openssl sha256sum; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    red "  missing: $tool"
    exit 1
  fi
done
dim "  node $(node -v), npm $(npm -v)"

# --------------------------------------------------------------------------
echo "==> installing pinned dependencies"
# `npm ci` rather than `npm install`: it installs exactly the lockfile and
# fails if package.json and the lockfile disagree. `npm install` would happily
# resolve a newer version and silently change the output.
if ! (cd "$ROOT/frontend" && npm ci --no-audit --no-fund > "$WORK/npm.log" 2>&1); then
  red "  npm ci failed"
  tail -20 "$WORK/npm.log"
  exit 1
fi
dim "  ok"

# --------------------------------------------------------------------------
echo "==> building from source"
if ! "$ROOT/scripts/build.sh" > "$WORK/build.log" 2>&1; then
  red "  build failed"
  tail -20 "$WORK/build.log"
  exit 1
fi

LOCAL_MANIFEST="$ROOT/public/assets/manifest.json"
read_field() { python3 -c "import json,sys;print(json.load(open(sys.argv[1]))[sys.argv[2]])" "$1" "$2"; }

LOCAL_JS="$(read_field "$LOCAL_MANIFEST" js)"
LOCAL_CSS="$(read_field "$LOCAL_MANIFEST" css)"
LOCAL_JS_SRI="$(read_field "$LOCAL_MANIFEST" jsSri)"
LOCAL_CSS_SRI="$(read_field "$LOCAL_MANIFEST" cssSri)"
dim "  built $LOCAL_JS"
dim "  built $LOCAL_CSS"

# --------------------------------------------------------------------------
echo "==> fetching the live page"
if ! curl -fsS -A "notemy-verify" "$TARGET/" -o "$WORK/index.html"; then
  red "  could not fetch $TARGET/"
  exit 1
fi

# Pull each stylesheet/script reference together with its integrity attribute.
extract() {
  python3 - "$WORK/index.html" "$1" <<'PY'
import re, sys
html = open(sys.argv[1], encoding="utf-8", errors="replace").read()
kind = sys.argv[2]
pattern = (r'<script[^>]+src="([^"]+)"[^>]*integrity="([^"]+)"'
           if kind == "js" else
           r'<link[^>]+href="([^"]+\.css)"[^>]*integrity="([^"]+)"')
m = re.search(pattern, html)
print(f"{m.group(1)}\t{m.group(2)}" if m else "")
PY
}

check_asset() {
  local kind="$1" local_name="$2" local_sri="$3"
  local line url claimed served_sri

  line="$(extract "$kind")"
  if [ -z "$line" ]; then
    red "  $kind: no $kind reference with an integrity attribute found in the page"
    fail=1
    return
  fi

  url="${line%%$'\t'*}"
  claimed="${line##*$'\t'}"

  echo
  echo "  $kind"
  dim "    served as:  ${url##*/}"
  dim "    built as:   $local_name"

  if ! curl -fsS -A "notemy-verify" "$TARGET$url" -o "$WORK/served.$kind"; then
    red "    could not download $TARGET$url"
    fail=1
    return
  fi

  served_sri="sha384-$(openssl dgst -sha384 -binary "$WORK/served.$kind" | openssl base64 -A)"

  # 1. The page's own claim must match the bytes it served. A mismatch here
  #    means the browser would refuse to run the file anyway.
  if [ "$claimed" = "$served_sri" ]; then
    green "    [ok] page's integrity attribute matches the bytes served"
  else
    red   "    [!!] integrity attribute does NOT match the bytes served"
    dim   "         claimed: $claimed"
    dim   "         actual:  $served_sri"
    fail=1
  fi

  # 2. The real test: those bytes must equal what this source tree produces.
  if cmp -s "$WORK/served.$kind" "$ROOT/public/assets/$local_name"; then
    green "    [ok] served bytes are identical to the local build"
  else
    red   "    [!!] served bytes DIFFER from the local build"
    dim   "         local:  $local_sri"
    dim   "         served: $served_sri"
    dim   "         sizes:  $(stat -c%s "$ROOT/public/assets/$local_name") vs $(stat -c%s "$WORK/served.$kind") bytes"
    fail=1
  fi
}

check_asset js  "$LOCAL_JS"  "$LOCAL_JS_SRI"
check_asset css "$LOCAL_CSS" "$LOCAL_CSS_SRI"

# --------------------------------------------------------------------------
echo
echo "==> checking for inline script"
# The CSP forbids inline script, but verify it independently: an inline block is
# exactly where a targeted backdoor would hide, and it would not be covered by
# either SRI check above.
INLINE="$(python3 - "$WORK/index.html" <<'PY'
import re, sys
html = open(sys.argv[1], encoding="utf-8", errors="replace").read()
bad = []
# Non-greedy on both the attributes and the body, so a reported snippet is
# the offending block itself rather than everything up to the next closing tag.
for m in re.finditer(r'<script([^>]*?)>(.*?)</script\s*>', html, re.S):
    attrs, body = m.group(1), m.group(2).strip()
    if 'src=' in attrs:
        continue
    if 'application/ld+json' in attrs:
        continue          # data block, never executed
    if body:
        bad.append(body[:120])
print(len(bad))
for b in bad:
    print(b)
PY
)"
if [ "$(echo "$INLINE" | head -1)" = "0" ]; then
  green "  [ok] no executable inline script on the page"
else
  red "  [!!] found inline script:"
  echo "$INLINE" | tail -n +2 | sed 's/^/       /'
  fail=1
fi

# --------------------------------------------------------------------------
echo
if [ "$fail" -eq 0 ]; then
  green "VERIFIED — $TARGET is serving this exact source."
  echo
  dim "Remember what this does and does not show: it covers the bundle served to"
  dim "you just now. It cannot rule out a server that targets someone else."
  exit 0
fi

red "VERIFICATION FAILED — do not enter anything sensitive at $TARGET."
echo
dim "Before assuming the worst: check that your checkout is at the commit the"
dim "site is running (compare against the hashes in the matching GitHub release),"
dim "and that your working tree is clean."
exit 1
