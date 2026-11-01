#!/usr/bin/env python3
"""Replay V6 against disposable V5 source; never touch a live application."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import build_manifest as build
import host_probe

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location('v6_installer_test', HERE / 'install_root.py')
DEPLOY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DEPLOY)
MANIFEST = json.loads((HERE / 'manifest.json').read_text())
MARKED = 'app/Http/Controllers/Dashboard/HomeController.php'
MARKER = b'\n// Independent production adjustment preserved by V6 activation.\n'


class PackageTests(unittest.TestCase):
    def test_generation_refuses_unsupported_abi_and_insufficient_headroom(self):
        gib = 1024 ** 3
        report = dict(architecture='x86_64', libraries=dict(glibc='2.34', glibcxx='3.4.30', cxxabi='1.3.13',
                      libgomp=True, libssl3=True, libcrypto3=True), python=[3, 8], cpu_allowed=2,
                      effective_available_memory_bytes=6*gib, tools=dict(ffmpeg=True, ffprobe=True),
                      mp3_encoder=True, disk_free_bytes={'/usr/local/lib': 4*gib})
        self.assertTrue(host_probe.assessment(report)['safe'])
        for field, value, reason in [('effective_available_memory_bytes', 5*gib, 'MEMORY'),
                                      ('cpu_allowed', 1, 'CPU'), ('mp3_encoder', False, 'AUDIO_TOOLS')]:
            bad = copy.deepcopy(report)
            bad[field] = value
            self.assertFalse(host_probe.assessment(bad)['safe'])
            self.assertIn(reason, host_probe.assessment(bad)['reasons'])
        for key, value in [('glibc', '2.28'), ('glibcxx', '3.4.29'), ('cxxabi', '1.3.12')]:
            bad = copy.deepcopy(report)
            bad['libraries'][key] = value
            self.assertIn(key.upper(), host_probe.assessment(bad)['reasons'])
        report['disk_free_bytes']['/usr/local/lib'] = 4*gib-1
        self.assertIn('DISK', host_probe.assessment(report)['reasons'])

    def fixture(self, project, marker=False):
        originals = {}
        for item in MANIFEST['files']:
            content = build.baseline(item['path'])
            if content is not None:
                if marker and item['path'] == MARKED:
                    content += MARKER
                target = project / item['path']
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(content)
                originals[item['path']] = content
        return originals

    def test_manifest_is_current_and_exact(self):
        self.assertEqual(MANIFEST['base_revision'], build.BASE_REVISION)
        self.assertEqual(MANIFEST['feature'], build.FEATURE)
        self.assertEqual({e['path'] for e in MANIFEST['files']}, build.ALLOWED)
        self.assertEqual(DEPLOY.ALLOWED, build.ALLOWED)
        with tempfile.TemporaryDirectory() as temporary:
            project = Path(temporary)
            self.fixture(project)
            for change in DEPLOY.plan(project, MANIFEST):
                self.assertEqual(change['after'], (build.ROOT / change['path']).read_bytes())

    def test_install_repeat_and_unrelated_live_change(self):
        with tempfile.TemporaryDirectory() as temporary:
            stage = Path(temporary)
            project = stage / 'project'
            project.mkdir()
            self.fixture(project, marker=True)
            changes = DEPLOY.plan(project, MANIFEST)
            def verify():
                for change in changes:
                    self.assertEqual((project / change['path']).read_bytes(), change['after'])
            DEPLOY.apply_plan(project, changes, stage / 'backup', (os.getuid(), os.getgid()), verify)
            for change in DEPLOY.plan(project, MANIFEST):
                self.assertEqual(change['before'], change['after'])
                expected = (build.ROOT / change['path']).read_bytes()
                if change['path'] == MARKED:
                    expected += MARKER
                self.assertEqual(change['after'], expected)

    def test_failed_verification_restores_every_original_and_removes_new_files(self):
        with tempfile.TemporaryDirectory() as temporary:
            stage = Path(temporary)
            project = stage / 'project'
            project.mkdir()
            originals = self.fixture(project, marker=True)
            def fail():
                raise RuntimeError('injected verification failure')
            with self.assertRaisesRegex(RuntimeError, 'injected verification failure'):
                DEPLOY.apply_plan(project, DEPLOY.plan(project, MANIFEST), stage / 'backup', (os.getuid(), os.getgid()), fail)
            for entry in MANIFEST['files']:
                target = project / entry['path']
                if entry['path'] in originals:
                    self.assertEqual(target.read_bytes(), originals[entry['path']])
                else:
                    self.assertFalse(target.exists())

    def test_conflicting_fragment_is_refused_before_application(self):
        with tempfile.TemporaryDirectory() as temporary:
            project = Path(temporary)
            self.fixture(project)
            target = project / MARKED
            target.write_bytes(target.read_bytes().replace(b'forActor(', b'independentlyChangedVoice(', 1))
            with self.assertRaises(RuntimeError):
                DEPLOY.plan(project, MANIFEST)

    def test_new_file_conflict_is_not_overwritten(self):
        with tempfile.TemporaryDirectory() as temporary:
            project = Path(temporary)
            self.fixture(project)
            item = next(e for e in MANIFEST['files'] if e['kind'] == 'new')
            target = project / item['path']
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(b'Independent existing content')
            with self.assertRaises(RuntimeError):
                DEPLOY.plan(project, MANIFEST)
            self.assertEqual(target.read_bytes(), b'Independent existing content')

    def test_symlink_path_is_refused(self):
        with tempfile.TemporaryDirectory() as temporary:
            project = Path(temporary)
            self.fixture(project)
            target = project / MARKED
            other = project / 'outside.php'
            target.rename(other)
            target.symlink_to(other)
            with self.assertRaises(RuntimeError):
                DEPLOY.plan(project, MANIFEST)

    def test_unreviewed_and_duplicate_app_paths_are_refused(self):
        with tempfile.TemporaryDirectory() as temporary:
            project = Path(temporary)
            self.fixture(project)
            for name in ['.env', '../outside', '/etc/passwd']:
                bad = copy.deepcopy(MANIFEST)
                bad['files'][0]['path'] = name
                with self.assertRaises(RuntimeError):
                    DEPLOY.plan(project, bad)
            duplicate = copy.deepcopy(MANIFEST)
            duplicate['files'][-1] = duplicate['files'][0]
            with self.assertRaises(RuntimeError):
                DEPLOY.plan(project, duplicate)

    def test_private_payload_has_exact_assets_and_no_model_weights(self):
        voice = build.ROOT / 'scripts/hologram_voice'
        asset_manifest = json.loads((voice / 'assets.json').read_text())
        expected = {'worker.py', 'bootstrap_runtime.py', 'assets.json', 'host_probe.py'} | {e['path'] for e in asset_manifest['local_assets']}
        self.assertEqual({e['path'] for e in MANIFEST['worker_files']}, expected)
        for entry in MANIFEST['worker_files']:
            source = HERE / 'host_probe.py' if entry['path'] == 'host_probe.py' else voice / entry['path']
            self.assertEqual(DEPLOY.payload(entry), source.read_bytes())
            self.assertNotIn(Path(entry['path']).suffix, {'.gguf', '.gz'})
            bad = copy.deepcopy(entry)
            bad['after_sha256'] = '0' * 64
            with self.assertRaises(RuntimeError):
                DEPLOY.payload(bad)

    def test_worker_payload_refuses_unreviewed_paths_conflicts_and_broken_links(self):
        with tempfile.TemporaryDirectory() as temporary:
            lib = Path(temporary) / 'lib'
            lib.mkdir()
            with patch.object(DEPLOY, 'LIB', lib):
                self.assertEqual(len(DEPLOY.worker_plan(MANIFEST)), 10)
                bad = copy.deepcopy(MANIFEST)
                bad['worker_files'][0]['path'] = '../outside'
                with self.assertRaises(RuntimeError):
                    DEPLOY.worker_plan(bad)
                leaf = lib / 'worker.py'
                leaf.write_bytes(b'Unreviewed existing service')
                with self.assertRaises(RuntimeError):
                    DEPLOY.worker_plan(MANIFEST)
                leaf.unlink()
                leaf.symlink_to(lib / 'missing-target')
                with self.assertRaises(RuntimeError):
                    DEPLOY.worker_plan(MANIFEST)

    @unittest.skipUnless(os.geteuid() == 0, 'Trusted production tenant manifests must be root-owned')
    def test_only_trusted_active_tenants_enter_worker_scopes(self):
        with tempfile.TemporaryDirectory() as temporary:
            stage = Path(temporary)
            project, tenants = stage / 'project', stage / 'owners'
            project.mkdir()
            identifier = '12345678-1234-1234-1234-123456789abc'
            slug = '0123456789abcdef'
            base = tenants / 'tenants' / identifier
            config = base / 'bootstrap/cache/config.php'
            config.parent.mkdir(parents=True)
            config.write_bytes(b'<?php return [];')
            public = project / 'public/dashboards' / slug
            public.mkdir(parents=True)
            deny = tenants / 'control/deny' / identifier
            deny.parent.mkdir(parents=True)
            deny.write_bytes(b'')
            facts = dict(format=1, state='active', id=identifier, slug=slug, base=str(base), prefix='',
                         public=str(public), host='go-' + slug + '.example.test',
                         origin='https://go-' + slug + '.example.test', config_sha256=build.digest(config.read_bytes()))
            runtime = base / 'runtime.json'
            runtime.write_text(json.dumps(facts))
            runtime.chmod(0o600)
            self.assertEqual([s['scope'] for s in DEPLOY.contexts(project, tenants)], ['central', 'tenant:' + identifier])
            deny.write_bytes(b'blocked')
            self.assertEqual([s['scope'] for s in DEPLOY.contexts(project, tenants)], ['central'])
            deny.write_bytes(b'')
            runtime.chmod(0o666)
            with self.assertRaises(RuntimeError):
                DEPLOY.contexts(project, tenants)
            runtime.chmod(0o600)
            facts['id'] = 'another-tenant'
            runtime.write_text(json.dumps(facts))
            with self.assertRaises(RuntimeError):
                DEPLOY.contexts(project, tenants)


if __name__ == '__main__':
    unittest.main()
