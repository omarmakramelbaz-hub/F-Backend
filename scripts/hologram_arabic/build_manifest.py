#!/usr/bin/env python3
"""Package the reviewed Arabic fallback against the exact installed voice release."""
import argparse
import difflib
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import tempfile

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
SPEC = importlib.util.spec_from_file_location('go_arabic_deploy', HERE / 'install_root.py')
DEPLOY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DEPLOY)
BASE_BLOBS = {
    'app/Providers/AppServiceProvider.php': 'e99c63196ba2097c923d3ff191189d3d571ad8cd',
    'public/dashboard/js/hud-commands.js': '63308dca6af8866a8b972a65e1ca5421c6baa5a3',
    'public/dashboard/css/hud-commands.css': '0dafb0288335cc55558fd62c83f461fab55f3136',
    'resources/views/admin/home_hud.blade.php': '1e09402f1b1e0bf9aa50389fbcfbc6a523e1ead5',
}
LIVING_COMMAND_BLOB = 'd4d9aca2f3588e609e243b58ee5560c98b1b87ac'


def baseline(path):
    sha = BASE_BLOBS[path]
    value = subprocess.check_output(['git', 'cat-file', 'blob', sha], cwd=ROOT)
    if hashlib.sha1(b'blob ' + str(len(value)).encode() + b'\0' + value).hexdigest() != sha:
        raise RuntimeError('Baseline Git blob identity changed')
    return value


def bounded(before, after):
    left = before.decode().replace('\r\n', '\n').splitlines(keepends=True)
    right = after.decode().replace('\r\n', '\n').splitlines(keepends=True)
    for context in [1, 2, 3, 4, 8]:
        edits = []
        for group in difflib.SequenceMatcher(None, left, right, autojunk=False).get_grouped_opcodes(context):
            old = ''.join(left[group[0][1]:group[-1][2]])
            new = ''.join(right[group[0][3]:group[-1][4]])
            if not old or not new or before.decode().count(old) != 1 or after.decode().count(new) != 1:
                break
            edits.append({'before': old, 'after': new})
        else:
            if edits:
                return edits
    raise RuntimeError('Could not isolate provider loader fragment')


def entry(path, content):
    return {'path': path, 'encoding': 'utf-8', 'content': content.decode(),
            'after_sha256': DEPLOY.digest(content)}


def build():
    entries = []
    for path in sorted(DEPLOY.ALLOWED):
        current = (ROOT / path).read_bytes()
        if path in BASE_BLOBS:
            before = baseline(path)
            if path in {'app/Providers/AppServiceProvider.php', 'resources/views/admin/home_hud.blade.php'}:
                data = {'path': path, 'kind': 'patch', 'before_sha256': DEPLOY.digest(before),
                        'after_sha256': DEPLOY.digest(current), 'edits': bounded(before, current)}
            else:
                data = entry(path, current)
                data.update(kind='replace', before_sha256=DEPLOY.digest(before))
                if path == 'public/dashboard/js/hud-commands.js':
                    living = subprocess.check_output(['git', 'cat-file', 'blob', LIVING_COMMAND_BLOB], cwd=ROOT)
                    data['accepted_before_sha256'] = [DEPLOY.digest(living)]
        else:
            data = entry(path, current)
            data.update(kind='new', before_sha256=None)
        entries.append(data)
    manifest = {'format': 1, 'feature': DEPLOY.FEATURE, 'base_revision': DEPLOY.BASE_REVISION,
                'files': entries, 'private_files': [entry(path, (HERE / path).read_bytes()) for path in sorted(DEPLOY.PRIVATE_FILES)]}
    with tempfile.TemporaryDirectory(prefix='go-arabic-manifest-') as temporary:
        project = Path(temporary)
        for path in BASE_BLOBS:
            target = project / path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(baseline(path))
        changes = DEPLOY.plan(project, manifest)
        for change in changes:
            if change['after'] != (ROOT / change['path']).read_bytes():
                raise RuntimeError('Manifest differs from reviewed source')
        DEPLOY.apply_plan(project, changes, project / 'backup', (project.stat().st_uid, project.stat().st_gid), lambda: None)
        if any(c['before'] != c['after'] for c in DEPLOY.plan(project, manifest)):
            raise RuntimeError('Manifest is not idempotent')
    destination = HERE / 'manifest.json'
    destination.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
    return destination


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--pin-runner', action='store_true')
    args = parser.parse_args()
    manifest = build()
    if args.pin_runner:
        runner = HERE / 'run_root.sh'
        content = runner.read_text()
        for key, source in [('INSTALL_SHA256', HERE / 'install_root.py'), ('MANIFEST_SHA256', manifest)]:
            content, count = re.subn(r'^' + key + r'=[^\n]+', key + '=' + DEPLOY.digest(source.read_bytes()), content, flags=re.MULTILINE)
            if count != 1:
                raise RuntimeError('Missing runner pin')
        runner.write_text(content)
    print(json.dumps({'status': 'GO_ARABIC_MANIFEST_BUILT', 'base_revision': DEPLOY.BASE_REVISION,
                      'manifest_sha256': DEPLOY.digest(manifest.read_bytes()),
                      'runner_sha256': DEPLOY.digest((HERE / 'run_root.sh').read_bytes())}))


if __name__ == '__main__':
    main()
