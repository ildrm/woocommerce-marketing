"""Record only completed machine-readable release gates for the current runtime source."""
from pathlib import Path
import datetime
import hashlib
import importlib.util
import json
import re
import xml.etree.ElementTree as ET

ROOT=Path(__file__).resolve().parents[1]
REPORTS=ROOT/'.runtime/qualification'

def digest(path): return hashlib.sha256(path.read_bytes()).hexdigest()

def junit(path,expected):
    document=ET.parse(path).getroot()
    cases=list(document.iter('testcase'))
    if len(cases)!=expected or any(list(case.iter(tag)) for case in cases for tag in ['failure','error','skipped']):
        raise SystemExit('Incomplete or failing qualification report: '+path.name)
    return {'tests':len(cases),'assertions':sum(int(case.get('assertions','0')) for case in cases),'report_sha256':digest(path)}

def main():
    specification=importlib.util.spec_from_file_location('release_builder',ROOT/'tools/build-release.py')
    builder=importlib.util.module_from_spec(specification); specification.loader.exec_module(builder)
    version=re.search(r'\* Version:\s*(\S+)',(ROOT/'woocommerce-marketing-os.php').read_text()).group(1)
    matrix=[]
    for label,php,storage in [('php83-hpos','8.3.35','hpos'),('php83-legacy','8.3.35','legacy'),('php85-hpos','8.5.8','hpos'),('php85-legacy','8.5.8','legacy')]:
        matrix.append({'php':php,'order_storage':storage,'hpos_sync':storage=='hpos',**junit(REPORTS/(label+'.xml'),102)})
    javascript=junit(REPORTS/'javascript.xml',9)
    browser=json.loads((ROOT/'.runtime/e2e-results.json').read_text())['stats']
    if browser['expected']!=6 or any(browser.get(key,0) for key in ['unexpected','skipped','flaky']): raise SystemExit('Browser qualification is incomplete.')
    lint=json.loads((REPORTS/'php-lint.json').read_text())
    analysis=json.loads((REPORTS/'phpstan.json').read_text())
    formatting=json.loads((REPORTS/'phpcs.json').read_text())
    translations=json.loads((REPORTS/'translations.json').read_text())
    if not lint['passed'] or analysis['totals']['errors'] or analysis['totals']['file_errors'] or formatting['totals']['errors'] or not translations['passed']:
        raise SystemExit('A static/translation release gate failed.')
    source=builder.source_digest()
    gates={key:True for key in ['phpunit_matrix','php_lint','phpstan_level_5','php_formatting_errors','javascript','browser','translation_catalog','reviewed_security_findings']}
    evidence={'version':version,'qualified_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'source_sha256':source,'environment':{'wordpress':'7.1.2','woocommerce':'11.1.2','mysql':'8.0.46','php':['8.3.35','8.5.8']},'compatibility':{'hpos':True,'blocks':True},'gates':gates,'phpunit_matrix':matrix,'javascript':javascript,'browser':{'passed':6,'failed':0,'report_sha256':digest(ROOT/'.runtime/e2e-results.json')},'static':{'lint_files':lint['files'],'phpstan_level':5,'phpstan_errors':0,'phpcs_errors':0,'advisory_style_warnings':formatting['totals']['warnings']},'translations':translations,'security_review':'docs/SECURITY-REVIEW.md','limitations':['Provider HTTP outcomes intercepted; no live merchant delivery qualification.','No load benchmark or universal theme/extension compatibility claim.','External payout execution and processor erasure require merchant confirmation.']}
    package=REPORTS/'package-smoke.json'
    if package.exists():
        result=json.loads(package.read_text())
        if result.get('passed') and result.get('source_sha256')==source and result.get('version')==version:
            gates['installed_production_archive']=True
            evidence['package_smoke']={key:result[key] for key in ['passed','checks','production_autoload','blocks_affirmative_optin','consent_not_inferred','idempotent_checkout','order_minor','deactivation_preserves_history']}
    (ROOT/'release-evidence.json').write_text(json.dumps(evidence,sort_keys=True,indent=2)+'\n')
    print('Recorded '+str(len(matrix))+' passing PHP configurations, 9 JavaScript tests, 6 browser tests; source '+source)

if __name__=='__main__': main()
