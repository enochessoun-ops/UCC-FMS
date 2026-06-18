#!/usr/bin/env python3
"""Clean deployment reset for AOI/SBS ERP.

Run from the application folder before first institutional deployment only:
    python3 institutional_reset.py --confirm RESET

It clears transactional records while preserving master setup such as users,
chart of accounts, settings, bank accounts, departments/units and default roles.
"""
import argparse
import importlib

parser = argparse.ArgumentParser()
parser.add_argument('--confirm', required=True, help='Must be RESET')
args = parser.parse_args()
if args.confirm != 'RESET':
    raise SystemExit('Refusing to run. Use --confirm RESET')

server = importlib.import_module('server')
server.init_db()
server.init_jv_db()
try:
    server.init_payroll_db()
except Exception:
    pass
session = {'username':'deployment-reset', 'role':'Admin'}
res = server.api_reset_for_deployment({'confirm':'RESET','notes':'Command line clean institutional deployment reset'}, session)
print(res)
if not res.get('ok'):
    raise SystemExit(1)
