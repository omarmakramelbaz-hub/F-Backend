#!/usr/bin/env python3
"""Build the reviewed visual-only release and prove every known live baseline."""
import argparse
import base64
import difflib
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
SPEC = importlib.util.spec_from_file_location('premium_release', HERE / 'install_root.py')
DEPLOY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DEPLOY)
INSTALLED_REVISION = '7dc7c60a8ff69ab03212c4aa44a47bc4724df148'
BASELINES = [DEPLOY.BASE_REVISION, '47c562af4a7a97509f1ae1947ebee11cc4da2b9a', INSTALLED_REVISION]
HUD_MOUNT = "    if (window.GoHudCommands) commands = window.GoHudCommands.mount(root, {duckGreeting: stopSpeech, setSpeaking: function (on) { if (robot) robot.setSpeaking(on); }});\n"
HUD_EDITS = [
    ('    var robot = null;\n', '    var robot = null, commands = null;\n'),
    ('fullViewport: true, interactive: false}', 'fullViewport: true, interactive: false, artwork: true}'),
    ("        var items = Array.from(root.querySelectorAll('a[href],button:not([hidden]):not([disabled])')); var first = items[0], last = items[items.length - 1];\n",
     "        var items = Array.from(root.querySelectorAll('a[href],button,input,textarea,select,[tabindex=\"0\"]')).filter(function (node) { return !node.disabled && node.getClientRects().length && !node.closest('[hidden]'); }); var first = items[0], last = items[items.length - 1];\n        if (!first) return;\n"),
    ('if (controller) controller.abort(); stopSpeech(); if (robot) robot.destroy(); robot = null;',
     'if (controller) controller.abort(); if (commands) commands.destroy(); commands = null; stopSpeech(); if (robot) robot.destroy(); robot = null;'),
    ('    wakeBackground(); enter.focus({preventScroll: true}); greeting();\n',
     HUD_MOUNT + '    wakeBackground(); enter.focus({preventScroll: true}); greeting();\n'),
]


def digest(value):
    return hashlib.sha256(value).hexdigest()


def baseline(relative, revision=None):
    revision = revision or DEPLOY.BASE_REVISION
    if revision not in BASELINES or relative not in DEPLOY.ALLOWED:
        raise RuntimeError('Unreviewed baseline source')
    snapshot = os.environ.get('GO_PREMIUM_BASELINES_DIR')
    if snapshot:
        root = Path(snapshot).resolve()
        index = json.loads((root / 'source-index.json').read_text())
        if revision not in index or relative not in index[revision]:
            raise RuntimeError('Baseline snapshot entry missing: ' + revision + ':' + relative)
        expected = index[revision][relative]
        if expected is None:
            return None
        if not isinstance(expected, str) or not re.fullmatch(r'[0-9a-f]{40}', expected):
            raise RuntimeError('Baseline snapshot blob identity invalid')
        data = (root / revision / relative).read_bytes()
        actual = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
        if actual != expected:
            raise RuntimeError('Baseline snapshot Git blob mismatch: ' + revision + ':' + relative)
        return data
    exists = subprocess.run(['git', 'cat-file', '-e', revision + '^{commit}'], cwd=ROOT, capture_output=True)
    if exists.returncode:
        raise RuntimeError('Reviewed baseline commit unavailable: ' + revision)
    listing = subprocess.run(['git', 'ls-tree', revision, '--', relative], cwd=ROOT, capture_output=True)
    if listing.returncode:
        raise RuntimeError('Cannot inspect reviewed baseline source: ' + relative)
    if not listing.stdout:
        return None
    result = subprocess.run(['git', 'show', revision + ':' + relative], cwd=ROOT, capture_output=True)
    if result.returncode:
        raise RuntimeError('Cannot retrieve reviewed baseline source: ' + relative)
    return result.stdout


def bounded(before, after):
    old, new = before.decode().replace('\r\n', '\n'), after.decode().replace('\r\n', '\n')
    left, right = old.splitlines(keepends=True), new.splitlines(keepends=True)
    # Small unique anchors preserve unrelated recent polling/greeting work.
    for context in [1, 2, 3, 4, 8, 16, 32]:
        edits = []
        for group in difflib.SequenceMatcher(None, left, right, autojunk=False).get_grouped_opcodes(context):
            first, last = group[0], group[-1]
            prior, updated = ''.join(left[first[1]:last[2]]), ''.join(right[first[3]:last[4]])
            if not prior or not updated or old.count(prior) != 1 or new.count(updated) != 1:
                break
            edits.append({'before': prior, 'after': updated})
        else:
            if edits:
                return edits
    raise RuntimeError('Cannot isolate unique reviewed source fragments')


def hud_edits(before, after):
    content = before.decode()
    for old, new in HUD_EDITS:
        if content.count(old) != 1:
            raise RuntimeError('Opening hook must be unique: ' + old[:60])
        content = content.replace(old, new, 1)
    if content.encode() != after:
        raise RuntimeError('Opening JavaScript contains changes outside the five reviewed hooks')
    return [{'before': old, 'after': new} for old, new in HUD_EDITS]


def apply_edits(content, edits):
    """Use only reviewed integration fragments on an alternate live source."""
    result = content.decode().replace('\r\n', '\n')
    for edit in edits:
        old, new = edit['before'], edit['after']
        if result.count(new) == 1 and result.replace(new, '', 1).count(old) == 0:
            continue
        if result.count(old) != 1 or result.count(new) != 0:
            raise RuntimeError('Bounded baseline integration conflicts')
        result = result.replace(old, new, 1)
    return result.encode()


def installed_guard(installed, current, earlier):
    """Recognize the installed integration even beside unrelated source edits."""
    old, new = installed.decode(), current.decode()
    guards = []
    for block in difflib.SequenceMatcher(None, old, new, autojunk=False).get_matching_blocks():
        for part in old[block.a:block.a + block.size].splitlines():
            guard = part.strip()
            if 16 <= len(guard) <= 8192 and old.count(guard) == 1 and new.count(guard) == 1 and all(guard not in source.decode() for source in earlier):
                guards.append(guard)
    return min(guards, key=len) if guards else None


def integration_variants(relative, current):
    original = baseline(relative)
    installed = baseline(relative, INSTALLED_REVISION)
    if original is None or installed is None:
        raise RuntimeError('Bounded integration source is missing: ' + relative)
    delta = bounded(installed, current) if installed != current else []
    if relative == 'public/dashboard/js/home-hud.js':
        primary = hud_edits(original, installed) + delta
    else:
        primary = bounded(original, current)
    earlier = [baseline(relative, revision) for revision in BASELINES if revision != INSTALLED_REVISION]
    guard = installed_guard(installed, current, earlier) if delta else None
    variants = []
    for revision in BASELINES:
        prior = baseline(relative, revision)
        if prior is None:
            raise RuntimeError('Bounded integration source is missing: ' + relative)
        edits = delta if revision == INSTALLED_REVISION and delta else primary
        target = apply_edits(prior, edits)
        if revision in {DEPLOY.BASE_REVISION, INSTALLED_REVISION} and target != current:
            raise RuntimeError('Bounded integration differs from reviewed source: ' + relative)
        variant = {'before_sha256': digest(prior), 'after_sha256': digest(target), 'edits': edits}
        if revision == INSTALLED_REVISION and delta and guard:
            variant['requires'] = [guard]
        if variant not in variants:
            variants.append(variant)
    return variants


def encoded(data):
    try:
        return {'encoding': 'utf-8', 'content': data.decode('utf-8')}
    except UnicodeDecodeError:
        return {'encoding': 'base64', 'content': base64.b64encode(data).decode('ascii')}


def build():
    entries = []
    for relative in sorted(DEPLOY.ALLOWED):
        path = ROOT / relative
        if not path.is_file():
            raise RuntimeError('Reviewed release source is missing: ' + relative)
        current = path.read_bytes()
        previous = [baseline(relative, revision) for revision in BASELINES]
        prior = previous[0]
        if all(current == content for content in previous):
            continue
        if relative in DEPLOY.PATCHED:
            if prior is None:
                raise RuntimeError('Bounded integration source is missing: ' + relative)
            item = {'path': relative, 'kind': 'patch', 'variants': integration_variants(relative, current)}
        else:
            known = sorted({digest(content) for content in previous if content is not None})
            kind = 'new' if not known else 'upsert' if any(content is None for content in previous) else 'replace'
            item = {'path': relative, 'kind': kind,
                    'before_sha256': known, 'after_sha256': digest(current), **encoded(current)}
        entries.append(item)
    if not entries:
        raise RuntimeError('No reviewed premium hologram changes exist')
    manifest = {'format': 1, 'feature': DEPLOY.FEATURE, 'base_revision': DEPLOY.BASE_REVISION, 'files': entries}
    for revision in BASELINES:
        with tempfile.TemporaryDirectory(prefix='go-premium-manifest-') as temporary:
            project = Path(temporary)
            for item in entries:
                previous = baseline(item['path'], revision)
                if previous is not None:
                    target = project / item['path']
                    target.parent.mkdir(parents=True, exist_ok=True)
                    target.write_bytes(previous)
            for change in DEPLOY.plan(project, manifest):
                if revision == DEPLOY.BASE_REVISION and change['after'] != (ROOT / change['path']).read_bytes():
                    raise RuntimeError('Payload differs from reviewed source: ' + change['path'])
                target = project / change['path']
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(change['after'])
            if any(c['before'] != c['after'] for c in DEPLOY.plan(project, manifest)):
                raise RuntimeError('Payload is not idempotent for ' + revision)
    destination = HERE / 'manifest.json'
    destination.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
    return manifest, destination


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--pin-runner', action='store_true')
    args = parser.parse_args()
    manifest, destination = build()
    result = {'status': 'GO_PREMIUM_MANIFEST_BUILT', 'files': [e['path'] for e in manifest['files']],
              'verified_baselines': BASELINES, 'manifest_sha256': digest(destination.read_bytes())}
    if args.pin_runner:
        launcher = HERE / 'run_root.sh'
        content = launcher.read_text()
        for label, source in [('INSTALL_SHA256', HERE / 'install_root.py'), ('MANIFEST_SHA256', destination)]:
            content, count = re.subn(r'^' + label + r'=[^\n]+', label + '=' + digest(source.read_bytes()), content, flags=re.MULTILINE)
            if count != 1:
                raise RuntimeError('Missing unique launcher hash slot: ' + label)
        launcher.write_text(content)
        result['runner_sha256'] = digest(launcher.read_bytes())
    print(json.dumps(result))


if __name__ == '__main__':
    main()
