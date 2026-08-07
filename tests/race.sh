#!/usr/bin/env bash
# Exactly-one-winner test for DELETE ... RETURNING.
# Usage: ./race.sh [rounds] [contenders] [isolation]
set -u

ROUNDS=${1:-20}
CONTENDERS=${2:-16}
ISOLATION=${3:-"READ COMMITTED"}
DIR="$(cd "$(dirname "$0")" && pwd)"
TMP=$(mktemp -d)

echo "rounds=$ROUNDS contenders=$CONTENDERS isolation='$ISOLATION'"

bad=0
errors=0
for r in $(seq 1 "$ROUNDS"); do
  id=$(php "$DIR/mknote.php")
  target=$(php -r 'echo microtime(true) + 0.6;')

  for c in $(seq 1 "$CONTENDERS"); do
    php "$DIR/race-worker.php" "$id" "$target" "$ISOLATION" > "$TMP/r${r}_c${c}.out" 2>&1 &
  done
  wait

  hits=$(cat "$TMP"/r${r}_c*.out | grep -c '^HIT:' || true)
  errs=$(cat "$TMP"/r${r}_c*.out | grep -c '^ERR:' || true)
  distinct=$(cat "$TMP"/r${r}_c*.out | grep '^HIT:' | sort -u | wc -l)

  errors=$((errors + errs))
  if [ "$hits" -ne 1 ] || [ "$errs" -ne 0 ]; then
    bad=$((bad + 1))
    echo "  round $r: hits=$hits distinct_payloads=$distinct errors=$errs"
    cat "$TMP"/r${r}_c*.out | grep '^ERR:' | sort -u | head -3
  fi
  rm -f "$TMP"/r${r}_c*.out
done

rm -rf "$TMP"
total=$((ROUNDS * CONTENDERS))
if [ "$bad" -eq 0 ]; then
  echo "OK: $ROUNDS/$ROUNDS rounds had exactly one winner ($total contenders total, $errors errors)"
  exit 0
else
  echo "FAILED: $bad/$ROUNDS rounds violated exactly-once ($errors errors)"
  exit 1
fi
