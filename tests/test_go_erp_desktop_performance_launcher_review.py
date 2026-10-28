"""Independent launcher checks; local Git fixtures never contact the network."""
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    'performance_launcher_independent_review',
    ROOT / 'deployment/run_go_erp_desktop_performance_root.py')
launcher = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(launcher)


def git(directory, *arguments, data=None):
    result = subprocess.run(
        ['/usr/bin/git', '-c', 'core.hooksPath=/dev/null', *arguments],
        cwd=directory, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        env={**launcher.ENVIRONMENT,
             'GIT_AUTHOR_NAME': 'Fixture', 'GIT_AUTHOR_EMAIL': 'fixture@example.invalid',
             'GIT_COMMITTER_NAME': 'Fixture', 'GIT_COMMITTER_EMAIL': 'fixture@example.invalid'},
        check=True)
    return result.stdout


class LauncherReview(unittest.TestCase):
    def test_actual_clone_rejects_broken_alternates_symlink_before_fetch(self):
        if os.geteuid() != 0:
            self.skipTest('The production launcher explicitly requires root-owned directories')
        with tempfile.TemporaryDirectory(prefix='performance-launcher-alternates-') as temporary:
            root = Path(temporary)
            repository = root / 'retained/release.git'
            repository.parent.mkdir(mode=0o700)
            git(root, 'init', '--quiet', '--bare', str(repository))
            stage = root / 'stage'
            stage.mkdir(mode=0o700)
            run = subprocess.run

            def clone_with_untrusted_alternates(*args, **kwargs):
                result = run(*args, **kwargs)
                command = args[0]
                if 'clone' in command and result.returncode == 0:
                    alternates = Path(command[-1]) / 'objects/info/alternates'
                    alternates.symlink_to(root / 'absent-peer-object-store')
                return result

            with patch.object(launcher.subprocess, 'run', clone_with_untrusted_alternates), \
                    patch.object(launcher, 'git') as fetch:
                with self.assertRaisesRegex(launcher.Stop, 'PACKAGE_ALTERNATES_NOT_ALLOWED'):
                    launcher.prepare_package(repository, stage)
            fetch.assert_not_called()

    def test_actual_private_git_copy_shares_no_objects_or_mutations(self):
        if os.geteuid() != 0:
            self.skipTest('The production launcher explicitly requires root-owned directories')
        with tempfile.TemporaryDirectory(prefix='performance-launcher-review-') as temporary:
            root = Path(temporary)
            work = root / 'work'
            work.mkdir(mode=0o700)
            git(work, 'init', '--quiet', '--initial-branch=main')
            (work / 'deployment').mkdir()
            source = b'ORIGINAL_ROOT = True\n'
            (work / 'deployment/go_erp_shared_workspace_root.py').write_bytes(source)
            git(work, 'add', '--all')
            git(work, 'commit', '--quiet', '-m', 'Local pinned fixture')
            revision = git(work, 'rev-parse', 'HEAD').strip().decode()
            repository = root / 'retained/release.git'
            repository.parent.mkdir(mode=0o700)
            git(root, 'clone', '--quiet', '--bare', '--no-hardlinks',
                str(work), str(repository))
            stage = root / 'stage'
            stage.mkdir(mode=0o700)
            before = {str(path.relative_to(repository)): path.read_bytes()
                      for path in repository.rglob('*') if path.is_file()}
            original_git = launcher.git
            fetches = []

            def offline_git(target, *arguments):
                if arguments[0] == 'fetch':
                    fetches.append(arguments)
                    return b''
                return original_git(target, *arguments)

            with patch.object(launcher, 'ROOT_REV', revision), \
                    patch.object(launcher, 'CORE', revision), \
                    patch.object(launcher, 'SEED', revision), \
                    patch.object(launcher, 'ROOT_SHA', launcher.sha(source)), \
                    patch.object(launcher, 'git', offline_git):
                copied = launcher.prepare_package(repository, stage)
            self.assertEqual(len(fetches), 1)
            self.assertEqual(fetches[0][-3:], (launcher.REMOTE, revision, revision))
            self.assertEqual(before,
                {str(path.relative_to(repository)): path.read_bytes()
                 for path in repository.rglob('*') if path.is_file()})
            objects = [path for path in (repository / 'objects').rglob('*') if path.is_file()]
            self.assertTrue(objects)
            for path in objects:
                target = copied / path.relative_to(repository)
                self.assertEqual(path.read_bytes(), target.read_bytes())
                self.assertNotEqual((path.stat().st_dev, path.stat().st_ino),
                                    (target.stat().st_dev, target.stat().st_ino))
            self.assertFalse(os.path.lexists(copied / 'objects/info/alternates'))
            (copied / 'review-only.txt').write_bytes(b'PRIVATE_COPY_ONLY\n')
            self.assertFalse((repository / 'review-only.txt').exists())


if __name__ == '__main__':
    unittest.main()
