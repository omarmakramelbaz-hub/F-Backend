#!/usr/bin/env python3
"""Inspect bounded release file metadata only; never apply the update."""
import ast, hashlib, json, os, socket, stat
from pathlib import Path

assert os.geteuid() == 0
assert socket.gethostname().split('.')[0] == 'srv1836524'
pins = None
for p in Path('/var/tmp').glob('go-erp-shared-workspace.*/root.py'):
    d = p.parent.lstat()
    if not stat.S_ISDIR(d.st_mode) or d.st_uid != 0 or stat.S_IMODE(d.st_mode) != 0o700 or p.parent.resolve() != p.parent:
        continue
    with os.fdopen(os.open(p, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as f:
        s = os.fstat(f.fileno())
        if not stat.S_ISREG(s.st_mode) or s.st_uid != 0 or s.st_nlink != 1 or s.st_size > 2 * 1024**2:
            continue
        raw = f.read(2 * 1024**2 + 1)
    if hashlib.sha256(raw).hexdigest() != '90d9dff5ff0fcadb4854f5f0dc2103dcfe8b9b25ef9922164fba1b6e690a03e9':
        continue
    for n in ast.parse(raw).body:
        if isinstance(n, ast.Assign) and len(n.targets) == 1 and isinstance(n.targets[0], ast.Name) and n.targets[0].id == 'PINS':
            pins = ast.literal_eval(n.value)
    break
if pins is None:
    raise SystemExit('GO_ERP_DIAG_RETAINED_PACKAGE_NOT_FOUND')

root = Path('/home/fasakha/public_html')
private = Path('/var/lib/go-erp-manual-updates/private')
targets = {}
for plan in pins['source_plans'].values():
    for name in plan['paths']:
        targets[root / name] = 'source'
        if name.endswith('.blade.php'):
            key = hashlib.sha1(('v2' + str(root / name)).encode()).hexdigest()
            targets[root / 'storage/framework/views' / (key + '.php')] = 'compiled'
targets[private / ('shared-workspace-root-' + pins['core'] + '.json')] = 'private'
audits = [
    private / 'audits' / ('shared-workspace-root-' + pins['core']),
    private / 'audits/workspace-source-bbeaa55b1e2bd548734237d844aedfd0bb640b6f312a988e06d5cf501780fd71',
    private / 'audits/workspace-source-1cde384859a557e75eb69c8eb294a8d8cd0c76e4ec2703b2287abe60448977d5',
    private / 'shared-workspace-activation',
]
stack = [(p, 0) for p in audits if p.exists()]
while stack:
    folder, depth = stack.pop()
    if folder.is_symlink() or folder.resolve() != folder:
        targets[folder] = 'private'
        continue
    for p in folder.iterdir():
        assert len(targets) < 8192, 'GO_ERP_DIAG_SCAN_LIMIT'
        if p.is_dir() and not p.is_symlink():
            if depth < 2:
                stack.append((p, depth + 1))
        else:
            targets[p] = 'private'
bad = []
checked = 0
for p, kind in sorted(targets.items(), key=lambda x: str(x[0])):
    try:
        s = p.lstat()
    except FileNotFoundError:
        continue
    checked += 1
    mode = stat.S_IMODE(s.st_mode)
    owners = {(0, 0)} if kind == 'private' else {(0, 0), (1001, 1003)}
    modes = {0o600} if kind == 'private' else {0o640, 0o644}
    if kind == 'compiled' and (s.st_uid, s.st_gid) == (1001, 1003):
        modes.add(0o664)
    limit = 1024**2 if kind == 'private' and p.name == 'artifact.zip' else 2 * 1024**2
    reasons = []
    if not stat.S_ISREG(s.st_mode): reasons.append('file_type')
    if s.st_nlink != 1: reasons.append('hard_links')
    if (s.st_uid, s.st_gid) not in owners: reasons.append('owner')
    if mode not in modes: reasons.append('mode')
    if not 0 <= s.st_size <= limit: reasons.append('size')
    if reasons:
        bad.append({'path': str(p), 'kind': kind, 'uid': s.st_uid, 'gid': s.st_gid, 'mode': oct(mode), 'links': s.st_nlink, 'bytes': s.st_size, 'reasons': reasons})
print('GO_ERP_FILE_METADATA_DIAG ' + json.dumps({'read_only': True, 'checked': checked, 'violations': bad}, separators=(',', ':')))
