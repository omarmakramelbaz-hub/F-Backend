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
                    patch.object(launcher, 'DEPENDENCY_REVISIONS', ()), \
                    patch.object(launcher, 'ROOT_SHA', launcher.sha(source)), \
                    patch.object(launcher, 'git', offline_git):
                copied = launcher.prepare_package(repository, stage)
            self.assertEqual(len(fetches), 1)
            self.assertEqual(fetches[0][-2:], (launcher.REMOTE, revision))
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

    def test_shallow_ref_less_retained_package_explicitly_refetches_all_pins(self):
        if os.geteuid() != 0:
            self.skipTest('The production launcher explicitly requires root-owned directories')
        with tempfile.TemporaryDirectory(prefix='performance-launcher-shallow-') as temporary:
            root = Path(temporary)
            work = root / 'public-fixture'
            work.mkdir(mode=0o700)
            git(work, 'init', '--quiet', '--initial-branch=main')
            (work / 'dependency.txt').write_bytes(b'FROZEN_RUNNER_DEPENDENCY\n')
            git(work, 'add', '--all')
            git(work, 'commit', '--quiet', '-m', 'Dependency fixture')
            dependency = git(work, 'rev-parse', 'HEAD').strip().decode()
            (work / 'deployment').mkdir()
            source = b'ORIGINAL_ROOT = True\n'
            (work / 'deployment/go_erp_shared_workspace_root.py').write_bytes(source)
            git(work, 'add', '--all')
            git(work, 'commit', '--quiet', '-m', 'Pinned root fixture')
            revision = git(work, 'rev-parse', 'HEAD').strip().decode()
            targets = []
            for label in ('seed', 'core'):
                (work / 'candidate.txt').write_text(label + '\n')
                git(work, 'add', '--all')
                git(work, 'commit', '--quiet', '-m', label)
                targets.append(git(work, 'rev-parse', 'HEAD').strip().decode())
            seed, core = targets
            repository = root / 'retained/release.git'
            repository.parent.mkdir(mode=0o700)
            git(root, 'init', '--quiet', '--bare', str(repository))
            # This matches the real retained launcher package: shallow objects
            # fetched by raw SHA, with FETCH_HEAD but no advertised refs.
            git(repository, 'fetch', '--quiet', '--depth=1', work.as_uri(), revision, dependency)
            self.assertTrue((repository / 'shallow').is_file())
            no_refs = subprocess.run(['/usr/bin/git', '--git-dir=' + str(repository), 'show-ref'],
                                     env=launcher.ENVIRONMENT, capture_output=True)
            self.assertEqual(no_refs.returncode, 1)
            self.assertEqual(no_refs.stdout, b'')
            before = {str(path.relative_to(repository)): path.read_bytes()
                      for path in repository.rglob('*') if path.is_file()}
            stage = root / 'stage'
            stage.mkdir(mode=0o700)
            original_git = launcher.git
            fetches = []
            clone_missing = []
            run = subprocess.run

            def actual_clone(*args, **kwargs):
                result = run(*args, **kwargs)
                command = args[0]
                if 'clone' in command and result.returncode == 0:
                    missing = run(['/usr/bin/git', '--git-dir=' + command[-1],
                                   'rev-parse', revision + '^{commit}'],
                                  env=launcher.ENVIRONMENT, capture_output=True)
                    clone_missing.append(missing.returncode != 0)
                return result

            def offline_git(target, *arguments):
                if arguments[0] == 'fetch':
                    fetches.append(arguments)
                    # Only the transport is replaced; Git performs the actual
                    # shallow fetch and exact commit verification below.
                    arguments = tuple(work.as_uri() if item == launcher.REMOTE else item
                                      for item in arguments)
                return original_git(target, *arguments)

            with patch.object(launcher, 'ROOT_REV', revision), \
                    patch.object(launcher, 'CORE', core), \
                    patch.object(launcher, 'SEED', seed), \
                    patch.object(launcher, 'DEPENDENCY_REVISIONS', (dependency, revision)), \
                    patch.object(launcher, 'ROOT_SHA', launcher.sha(source)), \
                    patch.object(launcher, 'git', offline_git), \
                    patch.object(launcher.subprocess, 'run', actual_clone):
                copied = launcher.prepare_package(repository, stage)
            # Git ignores --local for shallow sources and clones no objects
            # when they have no refs. The explicit raw pins restore all inputs.
            self.assertEqual(clone_missing, [True])
            self.assertEqual(len(fetches), 1)
            self.assertEqual(fetches[0][-5:], (launcher.REMOTE, revision, core, seed, dependency))
            for pinned in (revision, core, seed, dependency):
                self.assertEqual(original_git(copied, 'rev-parse', pinned + '^{commit}').strip(),
                                 pinned.encode())
            self.assertEqual(original_git(copied, 'show', dependency + ':dependency.txt'),
                             b'FROZEN_RUNNER_DEPENDENCY\n')
            self.assertEqual(original_git(copied, 'show', revision + ':deployment/go_erp_shared_workspace_root.py'),
                             source)
            self.assertEqual(before,
                {str(path.relative_to(repository)): path.read_bytes()
                 for path in repository.rglob('*') if path.is_file()})
            self.assertFalse(os.path.lexists(copied / 'objects/info/alternates'))


if __name__ == '__main__':
    unittest.main()
