"""cPanel / Passenger entry point (UCC-FMS).
On a cPanel server with "Setup Python App": application root = this folder,
application entry = passenger_wsgi.py. Set RENDER_DATA_DIR to a writable data
folder (e.g. ~/erp_data) in the app's environment variables."""
import os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
os.environ.setdefault('RENDER_DATA_DIR', os.path.expanduser('~/erp_data'))
os.makedirs(os.environ['RENDER_DATA_DIR'], exist_ok=True)
from app import app as application
