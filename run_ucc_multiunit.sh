#!/usr/bin/env bash
# Run the UCC multi-unit acceptance gate in-process against a throwaway database.
# Exits non-zero on any failure so it can gate a deploy alongside the smoke and
# regression suites.
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
DATADIR="$(mktemp -d /tmp/ucc_mu.XXXXXX)"
trap 'rm -rf "$DATADIR"' EXIT
cd "$APPDIR" && RENDER_DATA_DIR="$DATADIR" python3 ucc_multiunit_test.py 2>/dev/null
