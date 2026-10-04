"""Build a reproducible installable archive from qualified source, with production autoload only."""
from pathlib import Path
import hashlib
import json
import os
import re
import shutil
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SLUG = 'woocommerce-marketing-os'
ENTRIES = ['woocommerce-marketing-os.php', 'uninstall.php', 'src', 'assets', 'languages', 'readme.txt', 'LICENSE', 'composer.json', 'composer.lock', 'release-evidence.json', 'THIRD-PARTY-NOTICES.md', 'docs/RELEASE.md', 'docs/SECURITY-REVIEW.md']

def source_digest():
    files = [ROOT / 'woocommerce-marketing-os.php', ROOT / 'uninstall.php']
    files += sorted((ROOT / 'src').rglob('*.php'))
    files += sorted(p for p in (ROOT / 'assets').rglob('*') if p.is_file())
    digest = hashlib.sha256()
    for path in sorted(files):
        digest.update(path.relative_to(ROOT).as_posix().encode() + b'\0' + hashlib.sha256(path.read_bytes()).digest())
    return digest.hexdigest()

def main():
    main_file = (ROOT / 'woocommerce-marketing-os.php').read_text()
    version = re.search(r'\* Version:\s*(\S+)', main_file).group(1)
    evidence = json.loads((ROOT / 'release-evidence.json').read_text())
    if evidence['version'] != version or evidence['source_sha256'] != source_digest():
        raise SystemExit('Release evidence does not match the current source.')
    if not all(evidence.get('gates', {}).values()) or len(evidence.get('gates', {})) < 6:
        raise SystemExit('Required release gates have not passed.')
    forbidden = re.compile(r'\b(?:TODO|FIXME|PLACEHOLDER)\b|Automattic\\WooCommerce\\Internal|SELECT\s+\*', re.I)
    for path in (ROOT / 'src').rglob('*.php'):
        if forbidden.search(path.read_text()):
            raise SystemExit('Unqualified source marker or unsupported storage query: ' + str(path.relative_to(ROOT)))
    distribution = ROOT / 'dist'
    distribution.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='wmos-release-', dir='/private/tmp') as temporary:
        stage = Path(temporary) / SLUG
        stage.mkdir()
        for entry in ENTRIES:
            source = ROOT / entry
            if not source.exists():
                if entry == 'languages':
                    continue
                raise SystemExit('Missing release file: ' + entry)
            if source.is_dir():
                shutil.copytree(source, stage / entry)
            else:
                (stage / entry).parent.mkdir(parents=True,exist_ok=True)
                shutil.copy2(source, stage / entry)
        environment = dict(os.environ, COMPOSER_HOME='/private/tmp/wmos-composer', COMPOSER_CACHE_DIR='/private/tmp/wmos-composer-cache')
        result = subprocess.run(['composer', 'install', '--no-dev', '--classmap-authoritative', '--no-scripts', '--no-plugins', '--no-interaction'],cwd=stage,env=environment,capture_output=True,text=True)
        if result.returncode:
            raise SystemExit('Production autoloader generation failed.')
        if (stage / 'vendor' / 'phpunit').exists() or (stage / '.runtime').exists():
            raise SystemExit('Development files leaked into production staging.')
        files = sorted(p for p in stage.rglob('*') if p.is_file())
        manifest = {p.relative_to(stage).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest() for p in files}
        (stage / 'checksums.json').write_text(json.dumps(manifest,sort_keys=True,indent=2) + '\n')
        archive = distribution / f'{SLUG}-{version}.zip'
        with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as package:
            for path in sorted(p for p in stage.rglob('*') if p.is_file()):
                information = zipfile.ZipInfo(SLUG + '/' + path.relative_to(stage).as_posix(),date_time=(2026,1,1,0,0,0))
                information.external_attr = 0o100644 << 16
                information.compress_type = zipfile.ZIP_DEFLATED
                package.writestr(information,path.read_bytes())
        checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
        (distribution / (archive.name + '.sha256')).write_text(checksum + '  ' + archive.name + '\n')
        print(f'Built {archive.relative_to(ROOT)} ({archive.stat().st_size:,} bytes); SHA-256 {checksum}')

if __name__ == '__main__':
    main()
