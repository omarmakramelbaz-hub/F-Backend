#!/usr/bin/env python3
"""Build the reviewed visual-only release and prove both known live baselines."""
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
SPEC = importlib.util.spec_from_file_location('premium_release', HERE / 'install_root.py')
DEPLOY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DEPLOY)
BASELINES = [DEPLOY.BASE_REVISION, '47c562af4a7a97509f1ae1947ebee11cc4da2b9a']
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
    result = subprocess.run(['git', 'show', (revision or DEPLOY.BASE_REVISION) + ':' + relative], cwd=ROOT, capture_output=True)
    return result.stdout if result.returncode == 0 else None


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
            continue
        current, prior = path.read_bytes(), baseline(relative)
        if current == prior:
            continue
        if relative in DEPLOY.PATCHED:
            if prior is None:
                raise RuntimeError('Bounded integration source is missing: ' + relative)
            item = {'path': relative, 'kind': 'patch', 'before_sha256': digest(prior),
                    'after_sha256': digest(current), 'edits': hud_edits(prior, current) if relative == 'public/dashboard/js/home-hud.js' else bounded(prior, current)}
        else:
            known = sorted({digest(content) for revision in BASELINES for content in [baseline(relative, revision)] if content is not None})
            item = {'path': relative, 'kind': 'replace' if prior is not None else 'new',
                    'before_sha256': known if prior is not None else [], 'after_sha256': digest(current), **encoded(current)}
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
