#!/usr/bin/env python3
"""Build an allowlisted installation package; never include runtime credentials."""
from pathlib import Path
import hashlib
import re
import zipfile

root = Path(__file__).resolve().parent.parent
module = root / 'modules/addons/onetfiskal'
version = re.search(r"VERSION\s*=\s*'([0-9.]+)'", (module / 'lib/Receipt.php').read_text()).group(1)
files = [module / name for name in ['onetfiskal.php', 'bootstrap.php', 'hooks.php', 'receipt.php',
    'lib/Core.php', 'lib/Runtime.php', 'lib/Admin.php', 'lib/Synthetic.php',
    'lib/TestAdmin.php', 'lib/Receipt.php', 'lib/Journal.php', 'lib/Refund.php', 'lib/RefundAdmin.php', 'lib/Copy.php', 'lib/CopyAdmin.php', 'lib/.htaccess']]
files += [root / name for name in ['README.md', 'README-SR.md', 'ROADMAP.md',
    'docs/TESTING.md', 'docs/PHASE2-JOURNAL.md', 'docs/PHASE3-REFUNDS.md', 'docs/PHASE5-COPIES.md', 'config/onetfiskal.example.php', 'config/build-ca-bundle.php']]
files = sorted(set(files))
for path in files:
    if not path.is_file() or path.is_symlink():
        raise RuntimeError(f'Unexpected package input: {path}')
    if path.suffix.lower() in {'.pfx', '.p12', '.pem', '.key', '.cer', '.crt'}:
        raise RuntimeError('Credential file rejected')
    if '-----BEGIN PRIVATE KEY-----' in path.read_text() or '-----BEGIN RSA PRIVATE KEY-----' in path.read_text():
        raise RuntimeError('Private key material rejected')
dist = root / 'dist'
dist.mkdir(exist_ok=True)
output = dist / f'ONET-Fiskal-WHMCS-{version}-sandbox.zip'
with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
    checksums = []
    for path in files:
        rel = path.relative_to(root).as_posix()
        archive.write(path, rel)
        checksums.append(f'{hashlib.sha256(path.read_bytes()).hexdigest()}  {rel}')
    archive.writestr('SHA256SUMS', '\n'.join(checksums) + '\n')
with zipfile.ZipFile(output) as archive:
    if archive.testzip() is not None:
        raise RuntimeError('ZIP integrity check failed')
print(output)
