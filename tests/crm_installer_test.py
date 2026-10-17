#!/usr/bin/env python3
"""Exercise installer publication/rollback with a disposable application only."""
import contextlib
import difflib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('crm_installer', Path(__file__).parents[1] / 'deployment/apply_customer_crm.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)


class InstallerTest(unittest.TestCase):
    @contextlib.contextmanager
    def upgrade_fixture(self, entries, releases, live, failed_command=None):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'app'
            root.mkdir()
            for relative, body in live.items():
                target = root / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(body)
            manifest = {'version': 1, 'files': entries, 'migrations': []}
            original = INSTALLER.run
            def runner(args, cwd=None, timeout=120):
                if args[0] == 'git':
                    return original(args, cwd, timeout)
                return subprocess.CompletedProcess(args, 1 if failed_command in args else 0, b'', b'')
            with patch.object(INSTALLER, 'source', side_effect=lambda _r, _v, p: json.dumps(manifest).encode() if p.endswith('manifest.json') else releases[p]), \
                 patch.object(INSTALLER, 'run', runner), \
                 patch.object(INSTALLER.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=os.geteuid())), \
                 patch.object(sys, 'argv', ['apply', '--root', str(root), '--revision', 'a' * 40]), \
                 contextlib.redirect_stdout(io.StringIO()):
                yield root

    def upgrade_entry(self, relative, baseline, old, release):
        def delta(before, after):
            return ''.join(difflib.unified_diff(before.decode().splitlines(True), after.decode().splitlines(True),
                fromfile='a/' + relative, tofile='b/' + relative, n=1))
        entry = {'path': relative, 'before': hashlib.sha256(baseline).hexdigest() if baseline is not None else None,
            'after': hashlib.sha256(release).hexdigest(), 'patch': delta(baseline, release) if baseline is not None else '',
            'upgrades': [{'before': hashlib.sha256(old).hexdigest(), 'patch': delta(old, release)}]}
        if baseline is not None:
            # An older reviewed compatibility hunk must not masquerade as the latest upgrade.
            entry['compatible_patches'] = [delta(baseline, old)]
        return entry

    def test_pinned_release_upgrades_preserve_custom_edits_and_replay(self):
        relative = 'public/dashboard/js/fixture.js'
        baseline = b'// extension point\nfunction existing() {}\n// shared routes\n'
        old = baseline + b'function customerMode() { return "old"; }\n'
        release = old.replace(b'return "old"', b'return "verbal"')
        for added in (False, True):
            for customization in (False, True):
                with self.subTest(added=added, customization=customization):
                    entry = self.upgrade_entry(relative, None if added else baseline, old, release)
                    live = old.replace(b'// extension point', b'// independent live extension') if customization else old
                    expected = release.replace(b'// extension point', b'// independent live extension') if customization else release
                    with self.upgrade_fixture([entry], {relative: release}, {relative: live}) as root:
                        INSTALLER.main()
                        self.assertEqual((root / relative).read_bytes(), expected)
                        INSTALLER.main()
                        self.assertEqual((root / relative).read_bytes(), expected)

    def test_updated_cumulative_patch_installs_on_legacy_baseline(self):
        relative = 'routes/fixture.php'
        baseline = b'<?php\n// extension point\n// base routes\n'
        old = baseline + b'// customer routes v1\n'
        release = baseline + b'// customer routes v2\n'
        entry = self.upgrade_entry(relative, baseline, old, release)
        for customized in (False, True):
            with self.subTest(customized=customized):
                live = baseline.replace(b'extension point', b'independent live edit') if customized else baseline
                expected = release.replace(b'extension point', b'independent live edit') if customized else release
                with self.upgrade_fixture([entry], {relative: release}, {relative: live}) as root:
                    INSTALLER.main()
                    self.assertEqual((root / relative).read_bytes(), expected)
                    INSTALLER.main()
                    self.assertEqual((root / relative).read_bytes(), expected)

    def test_partial_or_conflicting_upgrade_never_counts_as_installed(self):
        relative = 'public/dashboard/js/fixture.js'
        baseline = b'// extension point\n// base routes\n'
        middle = b''.join(b'// stable middle %d\n' % n for n in range(10))
        old = baseline + b'// customer first v1\n' + middle + b'// customer second v1\n'
        release = old.replace(b'first v1', b'first v2').replace(b'second v1', b'second v2')
        for added in (False, True):
            entry = self.upgrade_entry(relative, None if added else baseline, old, release)
            for partial in (False, True):
                with self.subTest(added=added, partial=partial):
                    live = old.replace(b'second v1', b'second custom')
                    if partial:
                        live = live.replace(b'first v1', b'first v2')
                    with self.upgrade_fixture([entry], {relative: release}, {relative: live}) as root:
                        with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                            INSTALLER.main()
                        self.assertEqual((root / relative).read_bytes(), live)
                        self.assertEqual(list((root.parent / 'customer-crm-release-backups').glob('*/receipt.json')), [])

    def test_upgrade_application_failure_restores_exact_previous_live_release(self):
        relative = 'public/dashboard/js/fixture.js'
        baseline = b'// extension point\n// base routes\n'
        old = baseline + b'// customer v1\n'
        release = old.replace(b'customer v1', b'customer v2')
        live = old.replace(b'extension point', b'independent live edit')
        for added in (False, True):
            with self.subTest(added=added):
                entry = self.upgrade_entry(relative, None if added else baseline, old, release)
                with self.upgrade_fixture([entry], {relative: release}, {relative: live}, 'crm:check') as root:
                    with self.assertRaisesRegex(RuntimeError, 'APPLICATION_CHECK_FAILED: crm:check'):
                        INSTALLER.main()
                    self.assertEqual((root / relative).read_bytes(), live)
                    backup = next((root.parent / 'customer-crm-release-backups').glob('*/receipt.json'))
                    self.assertEqual((backup.parent / relative).read_bytes(), live)

    def test_added_file_and_base_conflicts_are_aggregated(self):
        paths = ['public/dashboard/js/first.js', 'routes/second.php']
        baseline = b'// expected baseline\n'
        old = baseline + b'// customer v1\n'
        release = baseline + b'// customer v2\n'
        entries = [self.upgrade_entry(paths[0], None, old, release), self.upgrade_entry(paths[1], baseline, old, release)]
        live = {relative: b'// incompatible custom implementation\n' for relative in paths}
        with self.upgrade_fixture(entries, {relative: release for relative in paths}, live) as root:
            with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW: public/dashboard/js/first.js, routes/second.php'):
                INSTALLER.main()
            for relative in paths:
                self.assertEqual((root / relative).read_bytes(), live[relative])

    def test_all_live_conflicts_are_reported_before_publication(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'app'
            files = ['routes/first.php', 'routes/second.php']
            original = b'<?php\n// custom live behavior\n'
            release = b'<?php\n// expected baseline\n// customer hook\n'
            entries = []
            for relative in files:
                target = root / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(original)
                entries.append({'path': relative, 'before': '0' * 64, 'after': hashlib.sha256(release).hexdigest(),
                    'patch': f'--- a/{relative}\n+++ b/{relative}\n@@ -2 +2,2 @@\n // expected baseline\n+// customer hook\n'})
            manifest = {'version': 1, 'files': entries, 'migrations': []}
            with patch.object(INSTALLER, 'source', side_effect=lambda _r, _v, p: json.dumps(manifest).encode() if p.endswith('manifest.json') else release), \
                 patch.object(INSTALLER.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=os.geteuid())), \
                 patch.object(sys, 'argv', ['apply', '--root', str(root), '--revision', 'a' * 40]), \
                 contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW: routes/first.php, routes/second.php'):
                    INSTALLER.main()
            for relative in files:
                self.assertEqual((root / relative).read_bytes(), original)
            self.assertEqual(list((root.parent / 'customer-crm-release-backups').glob('*/receipt.json')), [])

    def test_reviewed_go_lifecycle_variants_keep_every_existing_byte(self):
        project = Path(__file__).parents[1]
        entry = next(item for item in json.loads((project / 'deployment/customer_crm_manifest.json').read_text())['files']
                     if item['path'].endswith('/GoStoreBoardActions.php'))
        release = (project / entry['path']).read_bytes()
        hook = (b'            // Query-builder transitions do not emit Eloquent observer events.\n'
                b'            // Keep the purchase and its feedback clock inside this sale transaction.\n'
                b"            app(CustomerDirectorySync::class)->captureGoOrder(DB::table('go_store_orders')->find($id), $action === 'complete');\n")
        for variant in ('cancellation', 'pre_clock'):
            for conflicting in (False, True):
                with self.subTest(variant=variant, conflicting=conflicting), tempfile.TemporaryDirectory() as temporary:
                    root = Path(temporary) / 'app'
                    target = root / entry['path']
                    target.parent.mkdir(parents=True)
                    live = (project / 'tests/fixtures/crm_lifecycle' / (variant + '.php')).read_bytes()
                    live = live.replace(b'class GoStoreBoardActions', b'// Existing live extension is retained.\nclass GoStoreBoardActions')
                    if conflicting:
                        live = live.replace(b"'updated_at'=>now(),", b"'updated_at'=>customFinancialClock(),")
                    target.write_bytes(live)
                    manifest = {'version': 1, 'files': [entry], 'migrations': []}
                    original = INSTALLER.run
                    def runner(args, cwd=None, timeout=120):
                        if args[0] == 'git':
                            return original(args, cwd, timeout)
                        return subprocess.CompletedProcess(args, 0, b'', b'')
                    with patch.object(INSTALLER, 'source', side_effect=lambda _r, _v, p: json.dumps(manifest).encode() if p.endswith('manifest.json') else release), \
                         patch.object(INSTALLER, 'run', runner), \
                         patch.object(INSTALLER.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=os.geteuid())), \
                         patch.object(sys, 'argv', ['apply', '--root', str(root), '--revision', 'a' * 40]), \
                         contextlib.redirect_stdout(io.StringIO()):
                        if conflicting:
                            with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                                INSTALLER.main()
                            self.assertEqual(target.read_bytes(), live)
                        else:
                            INSTALLER.main()
                            INSTALLER.main()
                            installed = target.read_bytes()
                            self.assertEqual(installed.count(hook), 1)
                            self.assertEqual(installed.replace(hook, b''), live)

    def test_preserves_unrelated_live_edit_and_rolls_back_failed_checks(self):
        for failed_command in (None, 'crm:customers-sync', 'crm:check'):
            fail = failed_command is not None
            with self.subTest(failed_command=failed_command), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary) / 'app'
                root.mkdir()
                target = root / 'routes/fixture.php'
                target.parent.mkdir()
                before = b'<?php\n// unrelated setting\n// existing routes\n'
                after = before + b'// new customer routes\n'
                live = before.replace(b'unrelated setting', b'unrelated live extension')
                target.write_bytes(live)
                manifest = {'version': 1, 'files': [{'path': 'routes/fixture.php',
                    'before': hashlib.sha256(before).hexdigest(), 'after': hashlib.sha256(after).hexdigest(),
                    'patch': 'diff --git a/routes/fixture.php b/routes/fixture.php\n--- a/routes/fixture.php\n+++ b/routes/fixture.php\n@@ -3 +3,2 @@\n // existing routes\n+// new customer routes\n'}], 'migrations': []}
                def source(_root, _revision, relative):
                    return json.dumps(manifest).encode() if relative.endswith('manifest.json') else after
                original = INSTALLER.run
                def runner(args, cwd=None, timeout=120):
                    if args[0] == 'git':
                        return original(args, cwd, timeout)
                    if failed_command is not None and failed_command in args:
                        return subprocess.CompletedProcess(args, 1, b'', b'fixture failure')
                    return subprocess.CompletedProcess(args, 0, b'', b'')
                with patch.object(INSTALLER, 'source', source), patch.object(INSTALLER, 'run', runner), \
                     patch.object(INSTALLER.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=os.geteuid())), \
                     patch.object(sys, 'argv', ['apply', '--root', str(root), '--revision', 'a' * 40]), \
                     contextlib.redirect_stdout(io.StringIO()):
                    if fail:
                        with self.assertRaisesRegex(RuntimeError, 'APPLICATION_CHECK_FAILED'):
                            INSTALLER.main()
                        self.assertEqual(target.read_bytes(), live)
                    else:
                        INSTALLER.main()
                        self.assertEqual(target.read_bytes(), live + b'// new customer routes\n')
                        INSTALLER.main()
                        self.assertEqual(target.read_bytes(), live + b'// new customer routes\n')
                backups = list((root.parent / 'customer-crm-release-backups').glob('*/receipt.json'))
                self.assertEqual(len(backups), 1 if fail else 2)

    def test_conflicting_live_edit_fails_before_any_publication(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'app'
            root.mkdir()
            target = root / 'routes/fixture.php'
            target.parent.mkdir()
            live = b'<?php\n// custom replacement\n'
            target.write_bytes(live)
            after = b'<?php\n// expected baseline\n// new routes\n'
            manifest = {'version': 1, 'files': [{'path': 'routes/fixture.php', 'before': '0' * 64,
                'after': hashlib.sha256(after).hexdigest(), 'patch': '--- a/routes/fixture.php\n+++ b/routes/fixture.php\n@@ -2 +2,2 @@\n // expected baseline\n+// new routes\n'}], 'migrations': []}
            with patch.object(INSTALLER, 'source', side_effect=lambda _r, _v, p: json.dumps(manifest).encode() if p.endswith('manifest.json') else after), \
                 patch.object(INSTALLER.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=os.geteuid())), \
                 patch.object(sys, 'argv', ['apply', '--root', str(root), '--revision', 'a' * 40]), \
                 contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaisesRegex(RuntimeError, 'LIVE_CHANGE_NEEDS_REVIEW'):
                    INSTALLER.main()
            self.assertEqual(target.read_bytes(), live)
            self.assertEqual(list((root.parent / 'customer-crm-release-backups').glob('*/receipt.json')), [])


if __name__ == '__main__':
    unittest.main()
