#!/usr/bin/env python3
"""Build a reproducible WordPress.org ZIP; never include the legacy private updater."""
from pathlib import Path
import datetime
import hashlib
import json
import os
import re
import zipfile

root = Path(__file__).resolve().parent.parent
main = (root / 'finlinks-stablecoin-payments.php').read_text(encoding='utf-8')
readme = (root / 'readme.txt').read_text(encoding='utf-8')
version = re.search(r"define\( 'FINLINKS_WC_VERSION', '([^']+)'", main).group(1)
assert re.search(r'^ \* Version:\s*(\S+)', main, re.M).group(1) == version
assert re.search(r'^Stable tag:\s*(\S+)', readme, re.M).group(1) == version
assert 'pandafish620' in re.search(r'^Contributors:(.*)', readme, re.M).group(1)
excluded = {'class-finlinks-updater.php', 'class-finlinks-update-manifest.php', 'class-finlinks-update-package.php'}
files = [root / name for name in ('finlinks-stablecoin-payments.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'docs/INSTALL-PROD.md')]
for folder in ('includes', 'assets', 'languages'):
    files.extend(p for p in (root / folder).rglob('*') if p.is_file() and p.name not in excluded and not p.name.startswith('.'))
epoch = int(os.environ.get('SOURCE_DATE_EPOCH', '1704067200'))
timestamp = datetime.datetime.fromtimestamp(epoch, datetime.timezone.utc).timetuple()[:6]
outdir = root / 'dist'
outdir.mkdir(exist_ok=True)
out = outdir / f'finlinks-stablecoin-payments-{version}.zip'
entries = {}
with zipfile.ZipFile(out, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for path in sorted(files, key=lambda p: p.relative_to(root).as_posix()):
        relative = path.relative_to(root).as_posix()
        data = path.read_bytes()
        # Git checkouts use different line endings; canonicalize release text.
        if path.suffix in ('.php', '.js', '.css', '.txt', '.md', '.pot') or path.name == 'LICENSE':
            data = data.replace(b'\r\n', b'\n')
        if path.suffix == '.php':
            assert not re.search(rb'<\s*(script|style)\b', data, re.I), relative
        info = zipfile.ZipInfo('finlinks-stablecoin-payments/' + relative, timestamp)
        info.create_system = 3
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(info, data, compresslevel=9)
        entries[relative] = hashlib.sha256(data).hexdigest()
with zipfile.ZipFile(out) as archive:
    assert archive.testzip() is None
sha = hashlib.sha256(out.read_bytes()).hexdigest()
(outdir / (out.name + '.sha256')).write_text(sha + '  ' + out.name + '\n', encoding='utf-8')
(outdir / f'release-{version}.json').write_text(json.dumps({'version': version, 'file': out.name, 'bytes': out.stat().st_size, 'sha256': sha, 'files': entries}, indent=2) + '\n', encoding='utf-8')
print(f'{out}\n{out.stat().st_size} bytes\nSHA-256: {sha}\n{len(entries)} files')
