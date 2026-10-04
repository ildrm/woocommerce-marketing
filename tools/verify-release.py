"""Check archive integrity and exclude development fixtures and local credentials."""
from pathlib import Path, PurePosixPath
import argparse
import hashlib
import json
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SLUG = 'woocommerce-marketing-os'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('archive')
    archive = Path(parser.parse_args().archive).resolve()
    if archive.parent != ROOT / 'dist':
        raise SystemExit('Verify only the workspace distribution.')
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    if archive.with_suffix('.zip.sha256').read_text().split()[0] != digest:
        raise SystemExit('Archive checksum mismatch.')
    secrets = []
    password = ROOT / '.runtime/admin-password'
    if password.exists():
        secrets.append(password.read_bytes().strip())
    environment = ROOT / '.runtime/mysql.env'
    if environment.exists():
        secrets.extend(line.split(b'=', 1)[1].strip() for line in environment.read_bytes().splitlines() if line.startswith(b'MYSQL_ROOT_PASSWORD='))
    configuration = ROOT / '.runtime/wordpress/wp-config.php'
    if configuration.exists():
        secrets.extend(match.group(1) for match in re.finditer(rb"define\s*\(\s*['\"](?:WMOS_ENCRYPTION_KEY|DB_PASSWORD)['\"]\s*,\s*['\"]([^'\"]+)['\"]", configuration.read_bytes()))
    denied = {'.runtime', '.git', 'node_modules', 'tests', 'tools', 'phpunit', 'phpstan', 'php-stubs', 'squizlabs', 'wp-coding-standards', 'mu-plugins'}
    with zipfile.ZipFile(archive) as package:
        names = package.namelist()
        if len(names) != len(set(names)) or package.testzip() is not None:
            raise SystemExit('Archive entries are duplicate or corrupt.')
        files = {}
        for name in names:
            path = PurePosixPath(name)
            if path.is_absolute() or '..' in path.parts or path.parts[0] != SLUG or denied.intersection(path.parts):
                raise SystemExit('Unsafe or development entry: ' + name)
            content = package.read(name)
            if any(secret and len(secret) >= 16 and secret in content for secret in secrets):
                raise SystemExit('Local credential found in release entry: ' + name)
            if str(ROOT).encode() in content or b'/private/tmp/wmos-' in content:
                raise SystemExit('Local runtime path found in release entry: ' + name)
            files[path.relative_to(SLUG).as_posix()] = content
        checksums = json.loads(files.pop('checksums.json'))
        actual = {name: hashlib.sha256(content).hexdigest() for name, content in files.items()}
        if checksums != actual:
            raise SystemExit('Packaged file manifest mismatch.')
        evidence = json.loads(files['release-evidence.json'])
        if not evidence['gates'].get('installed_production_archive') or not all(evidence['gates'].values()):
            raise SystemExit('Packaged installation qualification is missing.')
    report = {'passed': True, 'archive': archive.name, 'sha256': digest, 'bytes': archive.stat().st_size, 'files': len(names), 'file_checksums_verified': True, 'development_files_absent': True, 'local_credentials_absent': True, 'local_runtime_paths_absent': True}
    (ROOT / '.runtime/qualification/package-audit.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report))


if __name__ == '__main__':
    main()
