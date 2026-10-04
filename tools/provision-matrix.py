"""Create independent local WordPress table prefixes; never alter a merchant installation."""
from pathlib import Path
import os
import subprocess
import argparse
import re

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / '.runtime'

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--prefix',default='wmos_hpos_')
    parser.add_argument('--storage',choices=['hpos','legacy'],default='hpos')
    args=parser.parse_args()
    if not re.fullmatch(r'wmos_[a-z0-9_]{1,40}_',args.prefix):
        raise SystemExit('Only isolated WMOS test prefixes are allowed.')
    environment = dict(os.environ, WMOS_TEST_PREFIX=args.prefix, WP_CLI_CACHE_DIR='/private/tmp/wmos-wp-cache')
    command = ['php','-d','max_execution_time=120','-d','memory_limit=512M', '/opt/homebrew/bin/wp', '--path=' + str(RUNTIME / 'wordpress')]
    def wp(*args):
        result = subprocess.run(command + list(args), env=environment, capture_output=True, text=True)
        if result.returncode:
            raise RuntimeError('Isolated matrix provisioning failed: ' + ' '.join(args[:2]))
        print('Isolated matrix: ' + ' '.join(args[:2]) + ' succeeded.')
    installed = subprocess.run(command + ['core', 'is-installed'],env=environment,capture_output=True).returncode == 0
    if not installed:
        wp('core','install','--url=http://127.0.0.1:8089','--title=WMOS HPOS tests','--admin_user=wmos_test_admin','--admin_email=admin@example.invalid','--admin_password=' + (RUNTIME / 'admin-password').read_text(),'--skip-email')
    wp('plugin','activate','woocommerce')
    wp('option','update','woocommerce_custom_orders_table_enabled','yes' if args.storage=='hpos' else 'no')
    wp('option','update','woocommerce_custom_orders_table_data_sync_enabled','yes' if args.storage=='hpos' else 'no')
    wp('plugin','activate','woocommerce-marketing-os')
    print('Independent ' + args.storage + ' integration store provisioned: ' + args.prefix)

if __name__ == '__main__':
    main()
