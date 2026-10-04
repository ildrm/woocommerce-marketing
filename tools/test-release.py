"""Install the complete ZIP in an independent isolated store; never alter merchant sites."""
from pathlib import Path
import argparse
import json
import os
import re
import shutil
import subprocess
import tempfile
import zipfile

ROOT=Path(__file__).resolve().parents[1]

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('archive')
    args=parser.parse_args()
    archive=Path(args.archive).resolve()
    if archive.parent != ROOT/'dist' or not archive.is_file():
        raise SystemExit('Only the built workspace distribution may be tested.')
    environment=dict(os.environ,WMOS_TEST_PREFIX='wmos_pkg_'+os.urandom(3).hex()+'_',WP_CLI_CACHE_DIR='/private/tmp/wmos-wp-cache')
    with tempfile.TemporaryDirectory(prefix='wmos-package-',dir='/private/tmp') as temporary:
        wordpress=Path(temporary)/'wordpress'
        source=(ROOT/'.runtime/wordpress').resolve()
        def ignored(directory,names):
            result=['debug.log'] if 'debug.log' in names else []
            if Path(directory)==source/'wp-content/plugins': result.append('woocommerce-marketing-os')
            return result
        shutil.copytree(source,wordpress,symlinks=True,ignore=ignored)
        base=['php','-d','memory_limit=512M','-d','error_reporting=24575','/opt/homebrew/bin/wp','--path='+str(wordpress)]
        def wp(*arguments):
            result=subprocess.run(base+list(arguments),env=environment,capture_output=True,text=True)
            if result.returncode: raise RuntimeError('Package installation failed: '+' '.join(arguments[:2]))
        wp('core','install','--url=http://127.0.0.1:8089','--title=WMOS packaged release tests','--admin_user=wmos_test_admin','--admin_email=admin@example.invalid','--admin_password='+(ROOT/'.runtime/admin-password').read_text().strip(),'--skip-email')
        wp('plugin','activate','woocommerce')
        wp('option','update','woocommerce_custom_orders_table_enabled','yes')
        wp('option','update','woocommerce_custom_orders_table_data_sync_enabled','yes')
        wp('option','update','woocommerce_default_country','US:CA')
        wp('option','update','woocommerce_cod_settings',json.dumps({'enabled':'yes','enable_for_virtual':'yes','title':'Cash on delivery'}),'--format=json')
        wp('plugin','install',str(archive),'--activate')
        wp('option','update','wmos_settings',json.dumps({'commerce_enabled':True,'tracking_enabled':True,'consent_policy_version':'package-smoke-v1','enabled_modules':['segments','campaigns','automations','programs','promotions','measurement'],'retention_days':90,'time_zone':'UTC'}),'--format=json')
        environment.update(WMOS_WORDPRESS_PATH=str(wordpress),WMOS_PACKAGED_PATH=str(wordpress/'wp-content/plugins/woocommerce-marketing-os'))
        result=subprocess.run(['php','-d','memory_limit=512M',str(ROOT/'tools/package-smoke.php')],env=environment,capture_output=True,text=True)
        try: report=json.loads(result.stdout)
        except json.JSONDecodeError:
            diagnostic=ROOT/'.runtime/qualification/package-smoke-diagnostic.log'
            diagnostic.write_text(result.stdout+'\n'+result.stderr+'\n'+((wordpress/'wp-content/debug.log').read_text() if (wordpress/'wp-content/debug.log').exists() else ''))
            diagnostic.chmod(0o600)
            # Only safe local error type/location is exposed; never print configuration or full SQL.
            match=re.search(r'(?:Fatal error|Uncaught [\w\\]+):?\s*([^\n]+)',result.stderr)
            raise RuntimeError('Packaged runtime did not produce a passing report. '+(match.group(1)[:180] if match else 'Inspect local test diagnostics.'))
        if result.returncode or report.get('passed') is not True: raise RuntimeError('Installed archive failed qualification.')
        report['archive']=archive.name
        with zipfile.ZipFile(archive) as package:
            report['source_sha256']=json.loads(package.read('woocommerce-marketing-os/release-evidence.json'))['source_sha256']
        (ROOT/'.runtime/qualification/package-smoke.json').write_text(json.dumps(report,indent=2)+'\n')
        print(json.dumps(report))

if __name__=='__main__': main()
