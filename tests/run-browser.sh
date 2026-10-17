#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
foresightQaDir="$(mktemp -d)"
php tests/webmail.php --mount=/mail --serve >"$foresightQaDir/server.log" 2>&1 &
foresightFixturePid=$!
trap 'kill "$foresightFixturePid" 2>/dev/null || true; wait "$foresightFixturePid" 2>/dev/null || true' EXIT
foresightFixtureUrl=''
for foresightAttempt in {1..120}; do
    foresightFixtureUrl="$(sed -n 's/^BROWSER_QA_URL=//p' "$foresightQaDir/server.log" | tail -n 1)"
    if [ -n "$foresightFixtureUrl" ]; then break; fi
    if ! kill -0 "$foresightFixturePid" 2>/dev/null; then
        sed -n '1,250p' "$foresightQaDir/server.log"
        exit 1
    fi
    sleep 1
done
if [ -z "$foresightFixtureUrl" ]; then
    sed -n '1,250p' "$foresightQaDir/server.log"
    exit 1
fi
export WEBMAIL_SCREENSHOTS="$foresightQaDir/screenshots"
"${FORESIGHT_NODE:-node}" tests/browser.cjs "$foresightFixtureUrl"
"${FORESIGHT_NODE:-node}" tests/column-resize.cjs "$foresightFixtureUrl"
"${FORESIGHT_NODE:-node}" tests/preview.cjs "$foresightFixtureUrl"
