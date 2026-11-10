#!/usr/bin/env python3
"""Disposable source/rollback tests; no production paths or business data."""
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import wave

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location('go_arabic_deploy', HERE / 'install_root.py')
D = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(D)
PROVIDER = 'app/Providers/AppServiceProvider.php'
SCRIPT = 'public/dashboard/js/hud-commands.js'
SERVICE = 'app/Services/Dashboard/HomeHologramArabicVoice.php'


def fixture(project):
    before, after = b'loader();\n', b'loader();\narabic();\n'
    for name, data in [(PROVIDER, before + b'// independent source\n'), (SCRIPT, b'old\n')]:
        path = project / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
    return {'format': 1, 'feature': D.FEATURE, 'base_revision': D.BASE_REVISION, 'files': [
        {'path': PROVIDER, 'kind': 'patch', 'before_sha256': D.digest(before), 'after_sha256': D.digest(after), 'edits': [{'before': before.decode(), 'after': after.decode()}]},
        {'path': SCRIPT, 'kind': 'replace', 'before_sha256': D.digest(b'old\n'), 'after_sha256': D.digest(b'new\n'), 'encoding': 'utf-8', 'content': 'new\n'},
        {'path': SERVICE, 'kind': 'new', 'before_sha256': None, 'after_sha256': D.digest(b'<?php\n'), 'encoding': 'utf-8', 'content': '<?php\n'},
    ]}


class PackageTests(unittest.TestCase):
    def test_apply_replay_preserves_provider_edits(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            manifest = fixture(root)
            D.apply_plan(root, D.plan(root, manifest), root / 'backup', (os.getuid(), os.getgid()), lambda: None)
            self.assertEqual((root / PROVIDER).read_bytes(), b'loader();\narabic();\n// independent source\n')
            self.assertTrue(all(c['before'] == c['after'] for c in D.plan(root, manifest)))

    def test_unknown_script_or_new_path_collision_refused(self):
        for path in [SCRIPT, SERVICE]:
            with self.subTest(path=path), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                manifest = fixture(root)
                target = root / path
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(b'operator changes\n')
                with self.assertRaisesRegex(D.ReviewError, 'SOURCE_CHANGED'):
                    D.plan(root, manifest)
                self.assertEqual(target.read_bytes(), b'operator changes\n')

    def test_payload_hash_duplicate_path_and_unreviewed_business_path_refused(self):
        for mode in ['hash', 'duplicate', 'path']:
            with self.subTest(mode=mode), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                manifest = fixture(root)
                if mode == 'hash': manifest['files'][1]['content'] += 'changed'
                if mode == 'duplicate': manifest['files'].append(manifest['files'][0])
                if mode == 'path': manifest['files'][1]['path'] = 'routes/admin.php'
                with self.assertRaises(D.ReviewError): D.plan(root, manifest)

    def test_failure_rolls_back_new_files_and_preserves_mode(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            manifest = fixture(root)
            (root / SCRIPT).chmod(0o640)
            def fail(): raise RuntimeError('validation failed')
            with self.assertRaisesRegex(RuntimeError, 'validation failed'):
                D.apply_plan(root, D.plan(root, manifest), root / 'backup', (os.getuid(), os.getgid()), fail)
            self.assertEqual((root / SCRIPT).read_bytes(), b'old\n')
            self.assertEqual((root / SCRIPT).stat().st_mode & 0o777, 0o640)
            self.assertFalse((root / SERVICE).exists())

    def test_concurrent_edit_before_or_during_apply_is_preserved(self):
        for when in ['before', 'during']:
            with self.subTest(when=when), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                manifest = fixture(root)
                changes = D.plan(root, manifest)
                def concurrent():
                    (root / SCRIPT).write_bytes(b'concurrent\n')
                    raise RuntimeError('failed')
                if when == 'before': (root / SCRIPT).write_bytes(b'concurrent\n')
                with self.assertRaises(Exception):
                    D.apply_plan(root, changes, root / 'backup', (os.getuid(), os.getgid()), concurrent)
                self.assertEqual((root / SCRIPT).read_bytes(), b'concurrent\n')

    def test_symlink_parent_and_hardlink_leaf_refused(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            manifest = fixture(root)
            os.link(root / SCRIPT, root / 'extra-link')
            with self.assertRaisesRegex(D.ReviewError, 'UNSAFE_FILE'): D.plan(root, manifest)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / 'public').symlink_to('/tmp', target_is_directory=True)
            with self.assertRaises(D.ReviewError): D.checked_path(root, SCRIPT)

    def test_wav_smoke_rejects_silence_or_invalid_shape(self):
        def audio(channels=1, sample=1):
            out = io.BytesIO()
            with wave.open(out, 'wb') as stream:
                stream.setparams((channels, 2, 22050, 0, 'NONE', 'not compressed'))
                stream.writeframes(sample.to_bytes(2, 'little') * channels * 11025)
            return out.getvalue()
        D.valid_wav(audio())
        for data in [audio(sample=0), audio(channels=2), b'not wave']:
            with self.assertRaises(D.ReviewError): D.valid_wav(data)

    def test_archive_identity_and_private_collisions_refused(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            bad = root / 'engine.whl'; bad.write_bytes(b'bad')
            with self.assertRaisesRegex(D.ReviewError, 'ENGINE_ARCHIVE_CHANGED'): D.engine_files(bad)
            with patch.object(D, 'LIB', root):
                (root / 'synthesize.py').write_bytes(b'unknown private helper')
                with self.assertRaisesRegex(D.ReviewError, 'PRIVATE_SOURCE_CHANGED'):
                    D.runtime_plan({'synthesize.py': b'known helper'})
                (root / 'synthesize.py').unlink()
                (root / 'synthesize.py').symlink_to(root / 'absent')
                with self.assertRaisesRegex(D.ReviewError, 'PRIVATE_SOURCE_CHANGED'):
                    D.runtime_plan({'synthesize.py': b'known helper'})

    def test_package_excludes_existing_greeting_worker_and_business_routes(self):
        self.assertNotIn('routes/admin.php', D.ALLOWED)
        self.assertNotIn('app/Services/Dashboard/HomeHologramVoice.php', D.ALLOWED)
        self.assertNotIn('public/dashboard/js/home-hud.js', D.ALLOWED)
        self.assertNotIn('config:clear', D.PHP_HELPER)
        self.assertIn('proc_open', D.PHP_HELPER)

    @unittest.skipUnless((HERE / 'manifest.json').exists(), 'release manifest not built')
    def test_manifest_matches_reviewed_source_and_is_replayable(self):
        spec = importlib.util.spec_from_file_location('go_arabic_builder', HERE / 'build_manifest.py')
        build = importlib.util.module_from_spec(spec); spec.loader.exec_module(build)
        manifest = json.loads((HERE / 'manifest.json').read_text())
        D.private_payload(manifest)
        for entry in manifest['private_files']:
            self.assertEqual(D.payload(entry), (HERE / entry['path']).read_bytes())
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for relative in build.BASE_BLOBS:
                target = root / relative; target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(build.baseline(relative))
            changes = D.plan(root, manifest)
            for change in changes:
                self.assertEqual(change['after'], (build.ROOT / change['path']).read_bytes())
            D.apply_plan(root, changes, root / 'backup', (os.getuid(), os.getgid()), lambda: None)
            self.assertTrue(all(c['before'] == c['after'] for c in D.plan(root, manifest)))

    def test_release_upgrades_confirmed_living_and_one_click_sources(self):
        import subprocess
        manifest = json.loads((HERE / 'manifest.json').read_text())
        project_root = HERE.parents[1]
        for revision in ['4f6e6985113da853368511e221f6e76473a9cd07', D.BASE_REVISION]:
            with self.subTest(revision=revision), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                for entry in manifest['files']:
                    if entry['kind'] == 'new': continue
                    content = subprocess.check_output(['git', 'show', revision + ':' + entry['path']], cwd=project_root)
                    target = root / entry['path']; target.parent.mkdir(parents=True, exist_ok=True); target.write_bytes(content)
                changes = D.plan(root, manifest)
                for change in changes:
                    self.assertEqual(change['after'], (project_root / change['path']).read_bytes())
                self.assertNotIn('public/dashboard/js/go-hologram.js', [c['path'] for c in changes])
                self.assertNotIn('resources/views/admin/login.blade.php', [c['path'] for c in changes])


if __name__ == '__main__':
    unittest.main()
