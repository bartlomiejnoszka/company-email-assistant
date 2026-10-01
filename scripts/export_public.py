#!/usr/bin/env python3
"""Create an allowlisted source archive without private history or local artifacts."""
from pathlib import Path
import argparse, tarfile, re
ROOT = Path(__file__).resolve().parents[1]
DIRECTORIES = ['src','config/packages','templates','translations','migrations','examples','docs','deploy','assets','tests','scripts','.github']
FILES = ['AGENTS.md','README.md','LICENSE','CONTRIBUTING.md','SECURITY.md','THIRD_PARTY_NOTICES.md','CHANGELOG.md','.env','.gitignore','.dockerignore','.php-cs-fixer.dist.php','phpstan.neon','composer.json','composer.lock','package.json','package-lock.json','phpunit.xml.dist','bin/console','public/index.php','public/router.php','public/assets/panel.css','config/bundles.php','config/routes.yaml','config/services.yaml','config/services_test.yaml']
def candidates():
    paths = [ROOT / name for name in FILES]
    for directory in DIRECTORIES:
        paths.extend(path for path in (ROOT / directory).rglob('*') if path.is_file())
    return sorted(set(paths))
def main():
    parser = argparse.ArgumentParser(); parser.add_argument('--check', action='store_true'); parser.add_argument('--output', default='dist/company-email-assistant.tar.gz'); args = parser.parse_args()
    paths = candidates(); problems = []
    forbidden = re.compile(r'/Users/[^/\s]+|BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY|sk-proj-[A-Za-z0-9_-]{10,}')
    for path in paths:
        relative = path.relative_to(ROOT)
        if path.is_symlink() or not path.is_file(): problems.append(str(relative)+': missing file or symlink'); continue
        # Do not scan this scanner's literal signatures.
        if relative.as_posix() == 'scripts/export_public.py': continue
        if forbidden.search(path.read_text()): problems.append(str(relative)+': private material pattern')
    if problems: raise SystemExit('Export review failed:\n'+'\n'.join(problems))
    if args.check: print(f'Reviewed {len(paths)} allowlisted files; no private material patterns found.'); return
    output = ROOT / args.output; output.parent.mkdir(parents=True, exist_ok=True)
    with tarfile.open(output, 'w:gz') as archive:
        for path in paths: archive.add(path, arcname='company-email-assistant/'+str(path.relative_to(ROOT)), recursive=False)
    print(f'Created {output.relative_to(ROOT)} with {len(paths)} files and no Git history.')
if __name__ == '__main__': main()
