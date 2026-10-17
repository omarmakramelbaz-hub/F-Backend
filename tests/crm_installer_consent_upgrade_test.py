#!/usr/bin/env python3
"""Full CRM release installs and upgrades against repository-owned application bytes.

Only release reads, account identity and PHP/artisan execution are replaced. Patch
checks, live-file publication, private backups and replay use the real installer.
"""
import contextlib
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


PROJECT = Path(__file__).resolve().parents[1]
DEPLOYED = '82358aa2f528ee63b3e95e00b6c61f34f8f19fc5'
COMPACT_DEPLOYED = 'cc71fb7882e88db07007ec93bfc08889ddc65351'
CONSENT_DEPLOYED = '162635ae19fcf825093570385b643d54975743c0'
PREVIOUS_DEPLOYED = 'd6be69b37a8e5815be498b2bedd6daacbea2b68f'
SPEC = importlib.util.spec_from_file_location('crm_consent_installer', PROJECT / 'deployment/apply_customer_crm.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)


class ConsentReleaseInstallerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.manifest = json.loads((PROJECT / 'deployment/customer_crm_manifest.json').read_text())
        cls.releases = {entry['path']: (PROJECT / entry['path']).read_bytes() for entry in cls.manifest['files']}
        for entry in cls.manifest['files']:
            if hashlib.sha256(cls.releases[entry['path']]).hexdigest() != entry['after']:
                raise AssertionError('Release manifest must be regenerated: ' + entry['path'])

    def git_source(self, revision, relative):
        result = subprocess.run(['git', 'show', revision + ':' + relative], cwd=PROJECT, capture_output=True)
        if result.returncode:
            self.assertTrue(b'does not exist' in result.stderr or b'exists on disk, but not in' in result.stderr, result.stderr.decode())
            return None
        return result.stdout

    def reviewed_baseline(self):
        # Reconstruct only the manifest's exact reviewed pre-feature bytes. The
        # older repository commit predates separately deployed WhatsApp inbox files.
        result = {}
        with tempfile.TemporaryDirectory(prefix='crm-reviewed-baseline-') as temporary:
            stage = Path(temporary)
            for entry in self.manifest['files']:
                relative = entry['path']
                if entry['before'] is None:
                    result[relative] = None
                    continue
                target = stage / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(self.releases[relative])
                if hashlib.sha256(target.read_bytes()).hexdigest() != entry['before']:
                    delta = stage / 'baseline.patch'
                    delta.write_text(entry['patch'])
                    reverse = subprocess.run(['git', 'apply', '--reverse', '--whitespace=nowarn', str(delta)], cwd=stage, capture_output=True)
                    self.assertEqual(reverse.returncode, 0, reverse.stderr.decode())
                result[relative] = target.read_bytes()
                self.assertEqual(hashlib.sha256(result[relative]).hexdigest(), entry['before'], relative)
        return result

    @contextlib.contextmanager
    def application(self, revision=None, custom=None, reviewed=None):
        custom = custom or {}
        with tempfile.TemporaryDirectory(prefix='crm-consent-release-') as temporary:
            root = Path(temporary) / 'app'
            root.mkdir()
            originals = {}
            for entry in self.manifest['files']:
                relative = entry['path']
                body = self.git_source(revision, relative) if reviewed is None else reviewed[relative]
                originals[relative] = body
                if body is not None:
                    target = root / relative
                    target.parent.mkdir(parents=True, exist_ok=True)
                    target.write_bytes(body + custom.get(relative, b''))
            original_run = INSTALLER.run
            commands = []
            def runner(args, cwd=None, timeout=120):
                if args[0] == 'git':
                    return original_run(args, cwd, timeout)
                self.assertEqual(args[0], 'fixture-php')
                commands.append(args[1:])
                return subprocess.CompletedProcess(args, 0, b'', b'')
            def source(_root, _revision, relative):
                return json.dumps(self.manifest).encode() if relative.endswith('customer_crm_manifest.json') else self.releases[relative]
            with patch.object(INSTALLER, 'source', source), \
                 patch.object(INSTALLER, 'run', runner), \
                 patch.object(INSTALLER.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=os.geteuid())), \
                 patch.object(sys, 'argv', ['apply', '--root', str(root), '--revision', 'a' * 40, '--php', 'fixture-php']), \
                 contextlib.redirect_stdout(io.StringIO()):
                yield root, originals, commands

    def assert_release(self, root, custom=None):
        custom = custom or {}
        for relative, release in self.releases.items():
            with self.subTest(path=relative):
                self.assertEqual((root / relative).read_bytes(), release + custom.get(relative, b''))

    def test_upgrade_complete_deployed_release_and_replay(self):
        for revision in (PREVIOUS_DEPLOYED, CONSENT_DEPLOYED, COMPACT_DEPLOYED, DEPLOYED):
            with self.subTest(revision=revision):
                with self.application(revision) as (root, _originals, commands):
                    INSTALLER.main()
                    self.assert_release(root)
                    INSTALLER.main()
                    self.assert_release(root)
                    self.assertEqual(2, sum(command[1:] == ['crm:check', '--activate-phone-cutover'] for command in commands))

    def test_upgrade_keeps_independent_live_php_and_javascript_comments(self):
        custom = {
            'app/Services/Dashboard/BranchCustomers.php': b'\n// Retained site-specific customer extension.\n',
            'public/dashboard/js/customer-crm.js': b'\n// Retained site-specific CRM enhancement.\n',
        }
        with self.application(PREVIOUS_DEPLOYED, custom) as (root, _originals, _commands):
            INSTALLER.main()
            self.assert_release(root, custom)
            INSTALLER.main()
            self.assert_release(root, custom)
            for relative, comment in custom.items():
                self.assertEqual((root / relative).read_bytes().count(comment), 1)

    def test_upgrade_current_release_keeps_live_takeaway_css_and_javascript_extensions(self):
        custom = {
            'public/dashboard/css/takeaway-pos.css': b'\n/* Retained site-specific takeaway styling. */\n.takeaway-site-extension { --live-accent: #f58220; }\n',
            'public/dashboard/js/takeaway-pos.js': b'\n/* Retained site-specific takeaway integration. */\nwindow.takeawaySiteExtension = true;\n',
        }
        with self.application(DEPLOYED, custom) as (root, _originals, _commands):
            INSTALLER.main()
            self.assert_release(root, custom)
            INSTALLER.main()
            self.assert_release(root, custom)
            for relative, extension in custom.items():
                self.assertEqual((root / relative).read_bytes().count(extension), 1)

    def test_fresh_install_on_reviewed_manifest_baseline_and_replay(self):
        with self.application(reviewed=self.reviewed_baseline()) as (root, originals, _commands):
            self.assertTrue(any(body is not None for body in originals.values()))
            self.assertTrue(any(body is None for body in originals.values()))
            INSTALLER.main()
            self.assert_release(root)
            INSTALLER.main()
            self.assert_release(root)


if __name__ == '__main__':
    unittest.main()
