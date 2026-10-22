#!/usr/bin/env python3
"""Run the real historical-checkout shell against a credential-free local fixture."""
import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest


ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / 'deployment/go_erp_current_candidate_ci.yml'
BASE = '75c3fe50b3609ef5822904a23d7c4ed0afd07eff'
COMPARISON = '3b7d53e6021ce825dd6fc56218cb6ce25f72b3b4'
ORIGIN = 'https://github.com/omarmakramelbaz-hub/F-Backend.git'
GITLINK = 'test.fasakhaninja.com/dev'


class HistoricalCheckoutTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-historical-checkout-')
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.upstream = self.base / 'upstream'
        self.workspace = self.base / 'candidate workspace'
        self.historical = self.workspace / 'historical-desktop-baseline'
        self.environment = {
            'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8',
            'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': os.devnull,
            'GIT_CONFIG_SYSTEM': os.devnull, 'GIT_TERMINAL_PROMPT': '0',
            'GIT_NO_REPLACE_OBJECTS': '1',
        }
        for repository in (self.upstream, self.workspace):
            repository.mkdir()
            self.git(repository, 'init', '--quiet', '--template=')
        (self.upstream / 'comparison.py').write_text('# immutable comparison helper\n')
        self.commit(self.upstream, 'comparison')
        self.comparison = self.git(self.upstream, 'rev-parse', 'HEAD').stdout.decode().strip()
        (self.upstream / 'baseline.txt').write_text('immutable baseline\n')
        self.git(self.upstream, 'add', 'baseline.txt')
        self.git(self.upstream, 'update-index', '--add', '--cacheinfo',
                 '160000,' + self.comparison + ',' + GITLINK)
        (self.upstream / GITLINK).mkdir(parents=True)
        self.git(self.upstream, '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid',
                 'commit', '--quiet', '-m', 'historical orphan gitlink')
        self.baseline = self.git(self.upstream, 'rev-parse', 'HEAD').stdout.decode().strip()
        (self.workspace / 'live.php').write_text('<?php // current candidate\n')
        self.commit(self.workspace, 'current candidate')
        self.current = self.git(self.workspace, 'rev-parse', 'HEAD').stdout
        self.git(self.workspace, 'config', 'http.https://github.com/.extraheader', 'PRIVATE_AUTH_SENTINEL')
        self.current_config = (self.workspace / '.git/config').read_bytes()

    def git(self, repository, *arguments, check=True):
        return subprocess.run(
            ['/usr/bin/git', '-c', 'core.hooksPath=/dev/null', '-c', 'submodule.recurse=false',
             '-C', str(repository), *arguments], env=self.environment, check=check,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)

    def commit(self, repository, message):
        self.git(repository, 'add', '.')
        self.git(repository, '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid',
                 'commit', '--quiet', '-m', message)

    def shell(self):
        workflow = WORKFLOW.read_text()
        marker = '      - name: Check out the immutable historical desktop baseline\n'
        step = workflow.split(marker, 1)[1].split('\n      - name:', 1)[0]
        script = textwrap.dedent(step.split('        run: |\n', 1)[1])
        # Only transport and object identities change. Execute the checked-in
        # workflow's actual guarded shell, including its isolated environment.
        for original, fixture in (
            (ORIGIN, self.upstream.as_uri()), (BASE, self.baseline),
            (COMPARISON, self.comparison),
            ('protocol.https.allow=always', 'protocol.file.allow=always'),
        ):
            self.assertIn(original, script)
            script = script.replace(original, fixture)
        return script

    def run_checkout(self, extra_environment=None):
        environment = dict(self.environment, GITHUB_WORKSPACE=str(self.workspace))
        environment.update(extra_environment or {})
        return subprocess.run(
            ['/bin/bash', '--noprofile', '--norc', '-euo', 'pipefail', '-c', self.shell()],
            cwd=self.workspace, env=environment, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, timeout=30)

    def assert_current_unchanged(self):
        self.assertEqual(self.git(self.workspace, 'rev-parse', 'HEAD').stdout, self.current)
        self.assertEqual((self.workspace / '.git/config').read_bytes(), self.current_config)
        self.assertEqual((self.workspace / 'live.php').read_text(), '<?php // current candidate\n')
        self.assertEqual(self.git(self.workspace, 'diff-index', '--name-only', 'HEAD', '--').stdout, b'')

    def test_orphan_gitlink_is_preserved_without_fatal_checkout_auth_cleanup(self):
        result = self.run_checkout()
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        self.assertEqual(self.git(self.historical, 'rev-parse', 'HEAD').stdout.decode().strip(), self.baseline)
        self.assertEqual(self.git(self.historical, 'diff-index', '--name-only', self.baseline, '--').stdout, b'')
        self.assertEqual(self.git(self.historical, 'show', self.comparison + ':comparison.py').stdout,
                         b'# immutable comparison helper\n')
        self.assertEqual(self.git(self.historical, 'ls-tree', 'HEAD', '--', GITLINK).stdout,
                         ('160000 commit ' + self.comparison + '\t' + GITLINK + '\n').encode())
        self.assertFalse((self.historical / '.gitmodules').exists())
        # This is the command family used by checkout@v4 removeAuth. The same
        # intact tree still reproduces the production failure after our checkout.
        cleanup = self.git(self.historical, 'submodule', 'foreach', '--recursive', 'true', check=False)
        self.assertEqual(cleanup.returncode, 128)
        self.assertIn(b'No url found for submodule path', cleanup.stderr)
        self.assert_current_unchanged()

    def test_private_config_credentials_hooks_and_git_environment_are_not_inherited(self):
        marker = self.base / 'credential-or-hook-executed'
        template = self.base / 'template'
        (template / 'hooks').mkdir(parents=True)
        hook = template / 'hooks/post-checkout'
        hook.write_text('#!/bin/sh\ntouch ' + str(marker) + '\n')
        hook.chmod(0o755)
        external = self.base / 'external.gitconfig'
        external.write_text('[credential]\n\thelper = "!touch ' + str(marker) + '"\n'
                            '[init]\n\ttemplateDir = ' + str(template) + '\n'
                            '[http "https://github.com/"]\n\textraheader = PRIVATE_CONFIG_SENTINEL\n')
        result = self.run_checkout({
            'GIT_CONFIG_GLOBAL': str(external), 'GIT_CONFIG_SYSTEM': str(external),
            'GIT_CONFIG_NOSYSTEM': '0', 'GIT_DIR': str(self.workspace / '.git'),
            'GIT_WORK_TREE': str(self.workspace), 'GIT_TEMPLATE_DIR': str(template),
            'GIT_CONFIG_COUNT': '1', 'GIT_CONFIG_KEY_0': 'core.hooksPath',
            'GIT_CONFIG_VALUE_0': str(template / 'hooks'),
            'GIT_EXEC_PATH': str(self.base / 'unavailable-git-helpers'),
            'GIT_ASKPASS': str(hook), 'SSH_ASKPASS': str(hook),
            'PRIVATE_ENVIRONMENT_SENTINEL': 'not-for-the-checkout',
        })
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        self.assertFalse(marker.exists())
        config = (self.historical / '.git/config').read_text()
        self.assertNotIn('extraheader', config)
        self.assertNotIn('credential', config)
        self.assertNotIn('SENTINEL', config)
        self.assertNotIn(b'SENTINEL', result.stdout + result.stderr)
        self.assertFalse((self.historical / '.git/hooks/post-checkout').exists())
        self.assert_current_unchanged()

    def test_existing_destination_and_dangling_link_are_refused_without_changes(self):
        self.historical.mkdir()
        preserved = self.historical / 'preserved.txt'
        preserved.write_text('existing data\n')
        result = self.run_checkout()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(preserved.read_text(), 'existing data\n')
        self.assertFalse((self.historical / '.git').exists())
        preserved.unlink()
        self.historical.rmdir()
        target = self.base / 'must-not-be-created'
        self.historical.symlink_to(target, target_is_directory=True)
        result = self.run_checkout()
        self.assertNotEqual(result.returncode, 0)
        self.assertTrue(self.historical.is_symlink())
        self.assertFalse(target.exists())
        self.assert_current_unchanged()


if __name__ == '__main__':
    unittest.main()
