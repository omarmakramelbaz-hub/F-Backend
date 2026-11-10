#!/usr/bin/env python3
"""Disposable tests for bounded visual deployment; no live host or database."""
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location('premium_installer', HERE / 'install_root.py')
DEPLOY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DEPLOY)
RENDERER = 'public/dashboard/js/go-hologram.js'
INTEGRATION = 'public/dashboard/js/home-hud.js'
NEW = 'public/dashboard/js/hud-commands.js'
BEFORE = b'// previous renderer\n'
AFTER = b'// reviewed renderer\n'
OLD_HOOK = 'function mount() {\n    render();\n}\n'
NEW_HOOK = 'function mount() {\n    render();\n    commands();\n}\n'


def fixture_manifest():
    return {'format': 1, 'feature': DEPLOY.FEATURE, 'base_revision': DEPLOY.BASE_REVISION, 'files': [
        {'path': RENDERER, 'kind': 'replace', 'before_sha256': [DEPLOY.digest(BEFORE)],
         'after_sha256': DEPLOY.digest(AFTER), 'encoding': 'utf-8', 'content': AFTER.decode()},
        {'path': INTEGRATION, 'kind': 'patch', 'before_sha256': DEPLOY.digest(OLD_HOOK.encode()),
         'after_sha256': DEPLOY.digest(NEW_HOOK.encode()), 'edits': [{'before': OLD_HOOK, 'after': NEW_HOOK}]},
        {'path': NEW, 'kind': 'new', 'before_sha256': [], 'after_sha256': DEPLOY.digest(b'// commands\n'),
         'encoding': 'utf-8', 'content': '// commands\n'},
    ]}


class PackageTests(unittest.TestCase):
    def fixture(self, project, marker=False):
        for relative, content in [(RENDERER, BEFORE), (INTEGRATION, OLD_HOOK.encode() + (b'// independent edit\n' if marker else b''))]:
            path = project / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(content)
        return fixture_manifest()

    def test_replace_patch_new_install_repeat_and_preserved_live_edit(self):
        with tempfile.TemporaryDirectory() as temporary:
            stage = Path(temporary)
            project = stage / 'app'
            project.mkdir()
            manifest = self.fixture(project, marker=True)
            changes = DEPLOY.plan(project, manifest)
            DEPLOY.apply_plan(project, changes, stage / 'backup', (os.getuid(), os.getgid()), lambda: None)
            self.assertEqual((project / INTEGRATION).read_bytes(), NEW_HOOK.encode() + b'// independent edit\n')
            self.assertEqual((project / RENDERER).read_bytes(), AFTER)
            self.assertTrue(all(c['before'] == c['after'] for c in DEPLOY.plan(project, manifest)))

    def test_unknown_renderer_is_refused_without_touching_any_source(self):
        with tempfile.TemporaryDirectory() as temporary:
            project = Path(temporary)
            manifest = self.fixture(project)
            (project / RENDERER).write_bytes(BEFORE + b'// live rendering edit\n')
            with self.assertRaisesRegex(DEPLOY.ReviewError, 'SOURCE_CHANGED'):
                DEPLOY.plan(project, manifest)
            self.assertEqual((project / INTEGRATION).read_bytes(), OLD_HOOK.encode())
            self.assertFalse((project / NEW).exists())

    def test_hash_corruption_duplicate_and_unreviewed_path_refused(self):
        with tempfile.TemporaryDirectory() as temporary:
            project = Path(temporary)
            original = self.fixture(project)
            for mode in ['hash', 'duplicate', 'path', 'whole_hook']:
                manifest = copy.deepcopy(original)
                if mode == 'hash':
                    manifest['files'][0]['content'] += 'corrupt'
                elif mode == 'duplicate':
                    manifest['files'].append(manifest['files'][0])
                elif mode == 'path':
                    manifest['files'][0]['path'] = 'routes/admin.php'
                else:
                    manifest['files'][0]['path'] = INTEGRATION
                    manifest['files'].pop(1)
                with self.subTest(mode=mode), self.assertRaises(DEPLOY.ReviewError):
                    DEPLOY.plan(project, manifest)

    def test_ambiguous_changed_or_partial_hook_refused(self):
        variants = [OLD_HOOK + OLD_HOOK, OLD_HOOK.replace('render()', 'liveRender()'), NEW_HOOK + OLD_HOOK]
        for text in variants:
            with self.subTest(text=text), tempfile.TemporaryDirectory() as temporary:
                project = Path(temporary)
                manifest = self.fixture(project)
                (project / INTEGRATION).write_text(text)
                with self.assertRaisesRegex(DEPLOY.ReviewError, 'SOURCE_CHANGED'):
                    DEPLOY.plan(project, manifest)

    def test_crlf_hooks_remain_crlf_and_replay(self):
        with tempfile.TemporaryDirectory() as temporary:
            stage = Path(temporary)
            project = stage / 'app'
            project.mkdir()
            manifest = self.fixture(project)
            (project / INTEGRATION).write_bytes(OLD_HOOK.replace('\n', '\r\n').encode())
            changes = DEPLOY.plan(project, manifest)
            DEPLOY.apply_plan(project, changes, stage / 'backup', (os.getuid(), os.getgid()), lambda: None)
            self.assertEqual((project / INTEGRATION).read_bytes(), NEW_HOOK.replace('\n', '\r\n').encode())
            self.assertTrue(all(c['before'] == c['after'] for c in DEPLOY.plan(project, manifest)))

    def test_failed_validation_restores_bytes_mode_and_removes_new_file(self):
        with tempfile.TemporaryDirectory() as temporary:
            stage = Path(temporary)
            project = stage / 'app'
            project.mkdir()
            manifest = self.fixture(project)
            (project / RENDERER).chmod(0o640)
            changes = DEPLOY.plan(project, manifest)
            def failed():
                raise DEPLOY.ReviewError('verification failed')
            with self.assertRaisesRegex(DEPLOY.ReviewError, 'verification failed'):
                DEPLOY.apply_plan(project, changes, stage / 'backup', (os.getuid(), os.getgid()), failed)
            self.assertEqual((project / RENDERER).read_bytes(), BEFORE)
            self.assertEqual((project / RENDERER).stat().st_mode & 0o777, 0o640)
            self.assertEqual((project / INTEGRATION).read_text(), OLD_HOOK)
            self.assertFalse((project / NEW).exists())
            self.assertEqual((stage / 'backup' / RENDERER).read_bytes(), BEFORE)

    def test_concurrent_source_change_is_preserved_during_rollback(self):
        with tempfile.TemporaryDirectory() as temporary:
            stage = Path(temporary)
            project = stage / 'app'
            project.mkdir()
            manifest = self.fixture(project)
            changes = DEPLOY.plan(project, manifest)
            def failed():
                (project / RENDERER).write_bytes(b'// concurrent operator edit\n')
                raise RuntimeError('runtime failed')
            with self.assertRaisesRegex(DEPLOY.ReviewError, 'ROLLBACK_CONFLICT'):
                DEPLOY.apply_plan(project, changes, stage / 'backup', (os.getuid(), os.getgid()), failed)
            self.assertEqual((project / RENDERER).read_bytes(), b'// concurrent operator edit\n')
            self.assertEqual((project / INTEGRATION).read_text(), OLD_HOOK)
            self.assertFalse((project / NEW).exists())

    def test_error_after_atomic_rename_still_rolls_back_published_file(self):
        with tempfile.TemporaryDirectory() as temporary:
            stage = Path(temporary)
            project = stage / 'app'
            project.mkdir()
            manifest = self.fixture(project)
            changes = DEPLOY.plan(project, manifest)
            write = DEPLOY.atomic_write
            calls = []
            def failed_after_rename(*args):
                write(*args)
                calls.append(args[0])
                if len(calls) == 1:
                    raise OSError('directory fsync failed after rename')
            with patch.object(DEPLOY, 'atomic_write', side_effect=failed_after_rename), self.assertRaises(OSError):
                DEPLOY.apply_plan(project, changes, stage / 'backup', (os.getuid(), os.getgid()), lambda: None)
            self.assertEqual((project / RENDERER).read_bytes(), BEFORE)
            self.assertEqual((project / INTEGRATION).read_text(), OLD_HOOK)
            self.assertFalse((project / NEW).exists())

    def test_concurrent_change_between_plan_and_apply_refused(self):
        with tempfile.TemporaryDirectory() as temporary:
            stage = Path(temporary)
            project = stage / 'app'
            project.mkdir()
            manifest = self.fixture(project)
            changes = DEPLOY.plan(project, manifest)
            (project / INTEGRATION).write_bytes(b'// independent operator change\n')
            with self.assertRaisesRegex(DEPLOY.ReviewError, 'CONCURRENT_SOURCE_CHANGE'):
                DEPLOY.apply_plan(project, changes, stage / 'backup', (os.getuid(), os.getgid()), lambda: None)
            self.assertEqual((project / RENDERER).read_bytes(), BEFORE)
            self.assertEqual((project / INTEGRATION).read_bytes(), b'// independent operator change\n')

    def test_symlink_parent_and_hardlink_leaf_refused(self):
        with tempfile.TemporaryDirectory() as temporary:
            stage = Path(temporary)
            project = stage / 'app'
            project.mkdir()
            outside = stage / 'outside'
            outside.mkdir()
            (project / 'public').symlink_to(outside, target_is_directory=True)
            with self.assertRaisesRegex(DEPLOY.ReviewError, 'DIRECTORY_CHANGED'):
                DEPLOY.plan(project, fixture_manifest())
        with tempfile.TemporaryDirectory() as temporary:
            project = Path(temporary)
            manifest = self.fixture(project)
            os.link(project / RENDERER, project / 'hardlink.js')
            with self.assertRaisesRegex(DEPLOY.ReviewError, 'UNSAFE_FILE'):
                DEPLOY.plan(project, manifest)

    def test_new_file_collision_refused(self):
        with tempfile.TemporaryDirectory() as temporary:
            project = Path(temporary)
            manifest = self.fixture(project)
            (project / NEW).write_text('// unrelated existing file\n')
            with self.assertRaisesRegex(DEPLOY.ReviewError, 'SOURCE_CHANGED'):
                DEPLOY.plan(project, manifest)

    def test_upsert_allows_absence_or_known_previous_payload_only(self):
        old, new = b'// previous commands\n', b'// living-avatar commands\n'
        entry = {'path': NEW, 'kind': 'upsert', 'before_sha256': [DEPLOY.digest(old)],
                 'after_sha256': DEPLOY.digest(new), 'encoding': 'utf-8', 'content': new.decode()}
        manifest = {'format': 1, 'feature': DEPLOY.FEATURE, 'base_revision': DEPLOY.BASE_REVISION, 'files': [entry]}
        for existing in [None, old, new, b'// independent commands\n']:
            with self.subTest(existing=existing), tempfile.TemporaryDirectory() as temporary:
                project = Path(temporary)
                target = project / NEW
                if existing is not None:
                    target.parent.mkdir(parents=True)
                    target.write_bytes(existing)
                if existing is not None and existing not in [old, new]:
                    with self.assertRaisesRegex(DEPLOY.ReviewError, 'SOURCE_CHANGED'):
                        DEPLOY.plan(project, manifest)
                    self.assertEqual(target.read_bytes(), existing)
                else:
                    self.assertEqual(DEPLOY.plan(project, manifest)[0]['after'], new)
        manifest['files'][0]['before_sha256'] = []
        with tempfile.TemporaryDirectory() as temporary, self.assertRaisesRegex(DEPLOY.ReviewError, 'BASE_HASH_INVALID'):
            DEPLOY.plan(Path(temporary), manifest)

    def test_bounded_variants_upgrade_prior_release_and_preserve_other_source(self):
        original = 'function mount() {\n    render();\n}\n'
        previous = original.replace('render();', 'render();\n    commands();')
        latest = original.replace('render();', 'render();\n    avatar();')
        variants = [{'before_sha256': DEPLOY.digest(before.encode()), 'after_sha256': DEPLOY.digest(latest.encode()),
                     'edits': [{'before': before, 'after': latest}]} for before in [original, previous]]
        manifest = {'format': 1, 'feature': DEPLOY.FEATURE, 'base_revision': DEPLOY.BASE_REVISION,
                    'files': [{'path': INTEGRATION, 'kind': 'patch', 'variants': variants}]}
        for before in [original, previous, latest]:
            with self.subTest(before=before), tempfile.TemporaryDirectory() as temporary:
                stage = Path(temporary)
                project = stage / 'app'
                target = project / INTEGRATION
                target.parent.mkdir(parents=True)
                target.write_text(before + '// independent polling remains\n')
                changes = DEPLOY.plan(project, manifest)
                self.assertEqual(changes[0]['after'], (latest + '// independent polling remains\n').encode())
                DEPLOY.apply_plan(project, changes, stage / 'backup', (os.getuid(), os.getgid()), lambda: None)
                self.assertEqual(DEPLOY.plan(project, manifest)[0]['before'], DEPLOY.plan(project, manifest)[0]['after'])

    def test_bounded_variants_reject_conflicting_results_and_invalid_variants(self):
        before = 'first();\nsecond();\n'
        manifest = {'format': 1, 'feature': DEPLOY.FEATURE, 'base_revision': DEPLOY.BASE_REVISION,
                    'files': [{'path': INTEGRATION, 'kind': 'patch', 'variants': [
                        {'before_sha256': DEPLOY.digest(before.encode()),
                         'after_sha256': DEPLOY.digest(before.replace('first', 'avatar').encode()),
                         'edits': [{'before': 'first();', 'after': 'avatar();'}]},
                        {'before_sha256': DEPLOY.digest(before.encode()),
                         'after_sha256': DEPLOY.digest(before.replace('second', 'avatar').encode()),
                         'edits': [{'before': 'second();', 'after': 'avatar();'}]},
                    ]}]}
        with tempfile.TemporaryDirectory() as temporary:
            project = Path(temporary)
            target = project / INTEGRATION
            target.parent.mkdir(parents=True)
            target.write_text(before)
            with self.assertRaisesRegex(DEPLOY.ReviewError, 'PATCH_AMBIGUOUS'):
                DEPLOY.plan(project, manifest)
            self.assertEqual(target.read_text(), before)
            manifest['files'][0]['variants'][1]['before_sha256'] = 'invalid'
            with self.assertRaisesRegex(DEPLOY.ReviewError, 'PATCH_HASH_INVALID'):
                DEPLOY.plan(project, manifest)

    def test_offline_baseline_snapshots_require_exact_git_blob_identity(self):
        spec = importlib.util.spec_from_file_location('premium_snapshot_builder', HERE / 'build_manifest.py')
        build = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(build)
        content = b'// known reviewed renderer\n'
        identity = hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            target = root / DEPLOY.BASE_REVISION / RENDERER
            target.parent.mkdir(parents=True)
            target.write_bytes(content)
            index = {DEPLOY.BASE_REVISION: {RENDERER: identity, NEW: None}}
            (root / 'source-index.json').write_text(json.dumps(index))
            with patch.dict(os.environ, {'GO_PREMIUM_BASELINES_DIR': str(root)}):
                self.assertEqual(build.baseline(RENDERER), content)
                self.assertIsNone(build.baseline(NEW))
                target.write_bytes(content + b'changed')
                with self.assertRaisesRegex(RuntimeError, 'Git blob mismatch'):
                    build.baseline(RENDERER)
                with self.assertRaisesRegex(RuntimeError, 'entry missing'):
                    build.baseline('public/dashboard/css/home-hud.css')

    def test_installed_variant_guard_excludes_older_partial_integration(self):
        original = '<section>\n<canvas></canvas>\n</section>\n'
        previous = original.replace('<canvas>', '<img data-approved-artwork>\n<canvas>')
        latest = previous.replace('<section>', '<section data-commands="0">')
        canonical = {'before_sha256': DEPLOY.digest(original.encode()), 'after_sha256': DEPLOY.digest(latest.encode()),
                     'edits': [{'before': original, 'after': latest}]}
        incremental = {'before_sha256': DEPLOY.digest(previous.encode()), 'after_sha256': DEPLOY.digest(latest.encode()),
                       'requires': ['<img data-approved-artwork>'],
                       'edits': [{'before': '<section>', 'after': '<section data-commands="0">'}]}
        entry = {'path': INTEGRATION, 'kind': 'patch', 'variants': [canonical, incremental]}
        for before in [original, previous, latest]:
            for suffix in ['', '// independent polling\n']:
                with self.subTest(before=before, suffix=suffix):
                    after = DEPLOY.patch_variant((before + suffix).encode(), entry)
                    self.assertEqual(after, (latest + suffix).encode())
                    self.assertEqual(DEPLOY.patch_variant(after, entry), after)

    def test_no_business_route_worker_or_desktop_path_in_allowlist(self):
        self.assertTrue(all(path.startswith(('public/dashboard/', 'resources/views/admin/')) for path in DEPLOY.ALLOWED))
        self.assertNotIn('routes/admin.php', DEPLOY.ALLOWED)
        self.assertNotIn('app/Services/Dashboard/HomeHologramVoice.php', DEPLOY.ALLOWED)
        self.assertNotIn('route:clear', DEPLOY.PROBE)
        self.assertNotIn('prewarm', DEPLOY.PROBE)

    @unittest.skipUnless((HERE / 'manifest.json').exists(), 'release not finalized')
    def test_voice_restore_preserves_living_scene_and_authentication(self):
        spec = importlib.util.spec_from_file_location('premium_builder', HERE / 'build_manifest.py')
        build = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(build)
        manifest = json.loads((HERE / 'manifest.json').read_text())
        with tempfile.TemporaryDirectory() as temporary:
            project = Path(temporary)
            for item in manifest['files']:
                previous = build.baseline(item['path'], build.LIVING_REVISION)
                if previous is not None:
                    path = project / item['path']
                    path.parent.mkdir(parents=True, exist_ok=True)
                    path.write_bytes(previous)
            changes = DEPLOY.plan(project, manifest)
            changed = [c for c in changes if c['before'] != c['after']]
            self.assertEqual([c['path'] for c in changed], ['public/dashboard/js/hud-commands.js', 'resources/views/admin/home_hud.blade.php'])
            self.assertEqual(changed[1]['after'], changed[1]['before'].replace(b'data-hud-commands="0"', b'data-hud-commands="1"', 1))

    @unittest.skipUnless((HERE / 'manifest.json').exists(), 'release not finalized')
    def test_release_manifest_matches_current_sources_and_reviewed_baselines(self):
        spec = importlib.util.spec_from_file_location('premium_builder', HERE / 'build_manifest.py')
        build = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(build)
        manifest = json.loads((HERE / 'manifest.json').read_text())
        self.assertEqual(manifest['feature'], DEPLOY.FEATURE)
        for revision in build.BASELINES:
            with tempfile.TemporaryDirectory() as temporary:
                stage = Path(temporary)
                project = stage / 'app'
                project.mkdir()
                for item in manifest['files']:
                    previous = build.baseline(item['path'], revision)
                    if previous is not None:
                        path = project / item['path']
                        path.parent.mkdir(parents=True, exist_ok=True)
                        path.write_bytes(previous)
                changes = DEPLOY.plan(project, manifest)
                if revision == DEPLOY.BASE_REVISION:
                    for change in changes:
                        self.assertEqual(change['after'], (build.ROOT / change['path']).read_bytes())
                DEPLOY.apply_plan(project, changes, stage / 'backup', (os.getuid(), os.getgid()), lambda: None)
                self.assertTrue(all(c['before'] == c['after'] for c in DEPLOY.plan(project, manifest)))
        # Each baseline also allows unrelated text outside the reviewed hooks.
        for revision in build.BASELINES:
            with tempfile.TemporaryDirectory() as temporary:
                project = Path(temporary)
                for item in manifest['files']:
                    previous = build.baseline(item['path'], revision)
                    if previous is not None:
                        target = project / item['path']
                        target.parent.mkdir(parents=True, exist_ok=True)
                        suffix = b'\n// independent source edit retained\n' if item['path'] in DEPLOY.PATCHED else b''
                        target.write_bytes(previous + suffix)
                changes = DEPLOY.plan(project, manifest)
                for change in changes:
                    if change['path'] in DEPLOY.PATCHED:
                        self.assertTrue(change['after'].endswith(b'\n// independent source edit retained\n'))
                    target = project / change['path']
                    target.parent.mkdir(parents=True, exist_ok=True)
                    target.write_bytes(change['after'])
                self.assertTrue(all(c['before'] == c['after'] for c in DEPLOY.plan(project, manifest)))


if __name__ == '__main__':
    unittest.main()
