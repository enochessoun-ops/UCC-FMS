#!/bin/bash
# UCC-FMS Startup Script — pins to port 8888
cd "$(dirname "$0")"
export SBS_PORT=8888
python3 server.py --port 8888
