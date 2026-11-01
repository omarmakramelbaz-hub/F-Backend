#!/usr/bin/env python3
"""Build the bounded V6 application/private-worker payload from reviewed files."""
import argparse
import base64
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
BASE_REVISION = '39e47892c7bee9b440721992a37b13f796225214'
FEATURE = 'hologram-accounts-20261101-v6'
ALLOWED = {
    'app/Http/Controllers/Dashboard/HomeController.php',
    'app/Providers/AppServiceProvider.php',
    'app/Services/Dashboard/HomeHologramMetrics.php',
    'app/Services/Dashboard/HomeHudLinks.php',
    'app/Services/Dashboard/HomeHologramVoice.php',
    'app/Http/Controllers/Dashboard/HomeHologramVoiceController.php',
    'app/Observers/HomeHologramVoiceObserver.php',
    'app/Console/Commands/PrewarmHomeHologramVoice.php',
    'config/home_hologram_voice.php',
    'routes/hologram_voice.php',
    'routes/admin.php',
    'public/dashboard/js/home-hud.js',
    'public/dashboard/js/home-overview.js',
}
ROUTE_HOOK_BEFORE = "    Route::group([ 'middleware' => 'IsAdmin'], function () {\n        require __DIR__.'/owner_dashboards.php';\n"
ROUTE_HOOK_AFTER = ROUTE_HOOK_BEFORE + "        require __DIR__.'/hologram_voice.php';\n"


def digest(value):
    return hashlib.sha256(value).hexdigest()


def encoded(relative, data):
    result = dict(path=relative, kind='new', before_sha256=None, after_sha256=digest(data))
    try:
        result.update(encoding='utf-8', content=data.decode('utf-8'))
    except UnicodeDecodeError:
        result.update(encoding='base64', content=base64.b64encode(data).decode('ascii'))
    return result


def bounded(before, after):
    old, new = before.decode(), after.decode()
    left, right = old.splitlines(keepends=True), new.splitlines(keepends=True)
    for context in [4, 8, 16, 32, 64, 128, 256]:
        edits = []
        for group in difflib.SequenceMatcher(None, left, right, autojunk=False).get_grouped_opcodes(context):
            first, last = group[0], group[-1]
            prior = ''.join(left[first[1]:last[2]])
            updated = ''.join(right[first[3]:last[4]])
            if not prior or not updated or old.count(prior) != 1 or new.count(updated) != 1:
                break
            edits.append(dict(before=prior, after=updated))
        else:
            if edits:
                return edits
    raise RuntimeError('Cannot isolate unique reviewed source fragments')


def baseline(relative):
    result = subprocess.run(['git', 'show', BASE_REVISION + ':' + relative], cwd=ROOT, capture_output=True)
    return result.stdout if result.returncode == 0 else None


def planner():
    # The already-tested V5 patch engine can also verify a V6 payload before the
    # new root installer exists. It performs no deployment during import.
    spec = importlib.util.spec_from_file_location('v6_patch_verifier', ROOT / 'deployment/hologram/apply_root.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    module.ALLOWED, module.FEATURE, module.BASE_REVISION = ALLOWED, FEATURE, BASE_REVISION
    return module


def build():
    entries = []
    for relative in sorted(ALLOWED):
        current = (ROOT / relative).read_bytes()
        prior = baseline(relative)
        if prior is None:
            entries.append(encoded(relative, current))
        elif prior == current:
            raise RuntimeError('Release allowlist contains an unchanged path: ' + relative)
        else:
            if relative == 'routes/admin.php':
                old, new = ROUTE_HOOK_BEFORE.encode(), ROUTE_HOOK_AFTER.encode()
                if prior.count(old) != 1 or current.count(new) != 1 or prior.replace(old, new, 1) != current:
                    raise RuntimeError('Voice route must be the unique reviewed authenticated include')
                edits = [dict(before=ROUTE_HOOK_BEFORE, after=ROUTE_HOOK_AFTER)]
            else:
                edits = bounded(prior, current)
            entries.append(dict(path=relative, kind='patch', before_sha256=digest(prior),
                                after_sha256=digest(current), edits=edits))
    voice = ROOT / 'scripts/hologram_voice'
    asset_manifest = json.loads((voice / 'assets.json').read_text())
    worker_sources = {'worker.py': voice / 'worker.py', 'bootstrap_runtime.py': voice / 'bootstrap_runtime.py',
                      'assets.json': voice / 'assets.json', 'host_probe.py': HERE / 'host_probe.py'}
    for item in asset_manifest['local_assets']:
        relative = item['path']
        path = voice / relative
        if Path(relative).is_absolute() or '..' in Path(relative).parts or path.is_symlink():
            raise RuntimeError('Unreviewed private asset path')
        data = path.read_bytes()
        if len(data) != item['size'] or digest(data) != item['sha256']:
            raise RuntimeError('Private asset differs from its pinned manifest')
        worker_sources[relative] = path
    workers = [encoded(relative, path.read_bytes()) for relative, path in sorted(worker_sources.items())]
    manifest = dict(format=1, feature=FEATURE, base_revision=BASE_REVISION, files=entries, worker_files=workers)
    engine = planner()
    with tempfile.TemporaryDirectory(prefix='go-v6-manifest-') as temporary:
        project = Path(temporary)
        for item in entries:
            prior = baseline(item['path'])
            if prior is not None:
                target = project / item['path']
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(prior)
        for change in engine.plan(project, manifest):
            if change['after'] != (ROOT / change['path']).read_bytes():
                raise RuntimeError('Payload differs from reviewed source')
            target = project / change['path']
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(change['after'])
        if any(c['before'] != c['after'] for c in engine.plan(project, manifest)):
            raise RuntimeError('Payload is not idempotent')
    destination = HERE / 'manifest.json'
    destination.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
    return manifest, destination


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--pin-runner', action='store_true')
    args = parser.parse_args()
    manifest, destination = build()
    result = dict(status='GO_HOLOGRAM_V6_MANIFEST_BUILT', files=len(manifest['files']),
                  worker_files=len(manifest['worker_files']), manifest_sha256=digest(destination.read_bytes()))
    if args.pin_runner:
        launcher = HERE / 'run_root.sh'
        content = launcher.read_text()
        for label, source in [('INSTALL_SHA256', HERE / 'install_root.py'), ('MANIFEST_SHA256', destination), ('PROBE_SHA256', HERE / 'host_probe.py')]:
            content, count = re.subn(r'^' + label + r'=[^\n]+', label + '=' + digest(source.read_bytes()), content, flags=re.MULTILINE)
            if count != 1:
                raise RuntimeError('Missing unique launcher hash slot: ' + label)
        launcher.write_text(content)
        result['runner_sha256'] = digest(launcher.read_bytes())
    print(json.dumps(result))


if __name__ == '__main__':
    main()
