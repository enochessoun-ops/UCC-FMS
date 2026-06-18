#!/bin/bash
# UCC-FMS — TRAINING / SANDBOX MODE launcher
# ──────────────────────────────────────────────
# This starts a SEPARATE instance of UCC-FMS that uses an isolated
# training database (ucc_fms_training.db).
# The live database is completely untouched.
#
# Usage:
#   chmod +x run_training.sh
#   ./run_training.sh
#
# Access:
#   http://localhost:8889/
#
# Reset training data (wipe all training entries and start fresh):
#   rm -f ucc_fms_training.db
#   ./run_training.sh
#
# Stop:
#   Press Ctrl+C in this terminal.
# ──────────────────────────────────────────────
set -e
cd "$(dirname "$0")"
echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║           UCC-FMS — TRAINING MODE                    "
echo "╠══════════════════════════════════════════════════════════╣"
echo "║  URL    : http://localhost:8889/                      "
echo "║  DB     : ucc_fms_training.db (isolated — live DB untouched)   "
echo "║  Reset  : rm -f ucc_fms_training.db && ./run_training.sh       "
echo "╚══════════════════════════════════════════════════════════╝"
echo ""
python3 server.py --training --port 8889
