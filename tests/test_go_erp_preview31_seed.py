"""Real temporary Git trees verify seed lineage and native-byte immutability."""
import importlib.util
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('preview31_seed_policy',
    ROOT / 'deployment/verify_go_erp_preview31_seed.py')
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)


class Preview31SeedTests(unittest.TestCase):
    def test_release_pins_the_reviewed_private_diagnostic_core(self):
        self.assertEqual(policy.CORE, '22f724b1ec75e8326e20305df40b91fe5640a7a7')
        self.assertEqual(policy.POLICY, 'native-close-reopen-program-contract-shared-workspace-v1')

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-preview31-policy-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.git('init', '--quiet', '--template=')
        for name, data in {
            'app/source.php': '<?php // fixed application\n',
            'desktop-pos/src/local-runtime.cjs': '// reviewed memory-safe native core\n',
            'desktop-pos/package.json': '{"version":"0.4.4-preview.30"}\n',
            'desktop-pos/package-lock.json': '{"version":"0.4.4-preview.30"}\n',
            'deployment/verify_go_erp_current_candidate_ci.py': '# immutable original verifier\n',
            '.github/workflows/go-erp-update-tests.yml': 'original reviewed checks\n',
        }.items():
            self.write(name, data)
        self.core = self.commit('reviewed fixed native core')
        self.policy_files()
        self.seed = self.commit('only three bounded seed policy files')

    def git(self, *arguments):
        return policy.git(self.root, *arguments)

    def write(self, name, data):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(data)

    def commit(self, message):
        self.git('add', '.')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost',
                 '-c', 'commit.gpgSign=false', 'commit', '--quiet', '-m', message)
        return self.git('rev-parse', 'HEAD').decode().strip()

    def policy_files(self):
        for name in policy.POLICY_FILES:
            self.write(name, '# reviewed policy ' + name + '\n')

    def candidate(self, extra=None):
        self.git('reset', '--hard', self.seed)
        value = {'baseRevision': self.seed, 'inventory': {'app/source.php': {'present': True}}}
        self.write(policy.CANDIDATE, json.dumps(value))
        self.write('app/source.php', '<?php // newly captured source\n')
        for name in policy.VERSION_FILES:
            self.write(name, '{"version":"0.4.4-preview.31"}\n')
        if extra:
            self.write(extra, '# forbidden candidate edit\n')
        return self.commit('captured candidate'), value

    def test_exact_three_file_single_parent_policy_seed_is_accepted(self):
        result = policy.validate_seed(self.root, self.seed, core=self.core)
        self.assertEqual(result, {'core': self.core, 'seed': self.seed,
                                 'policy_files': sorted(policy.POLICY_FILES)})
        self.assertEqual(policy.parents(self.root, self.seed), [self.core])

    def test_app_native_dependency_package_or_extra_policy_changes_are_refused(self):
        for name in ['app/source.php', 'desktop-pos/src/local-runtime.cjs',
                     'desktop-pos/src/dashboard-download.cjs', 'composer.lock',
                     'desktop-pos/runtime/composer.lock', 'desktop-pos/package.json',
                     'desktop-pos/package-lock.json', 'deployment/verify_go_erp_current_candidate_ci.py']:
            with self.subTest(name=name):
                self.git('reset', '--hard', self.core)
                self.policy_files()
                self.write(name, 'unreviewed change\n')
                with self.assertRaisesRegex(policy.SeedError, 'UNREVIEWED_POLICY_DELTA'):
                    policy.validate_seed(self.root, self.commit('unreviewed change'), core=self.core)

    def test_each_policy_file_is_required_and_links_or_executable_modes_are_refused(self):
        for omitted in policy.POLICY_FILES:
            with self.subTest(omitted=omitted):
                self.git('reset', '--hard', self.core)
                self.git('clean', '-fd')
                for name in policy.POLICY_FILES - {omitted}:
                    self.write(name, '# approved policy\n')
                with self.assertRaisesRegex(policy.SeedError, 'POLICY_INCOMPLETE'):
                    policy.validate_seed(self.root, self.commit('incomplete policy'), core=self.core)
        for link in (False, True):
            with self.subTest(link=link):
                self.git('reset', '--hard', self.core)
                self.git('clean', '-fd')
                self.policy_files()
                target = self.root / 'tests/test_go_erp_preview31_seed.py'
                if link:
                    target.unlink(); target.symlink_to('../app/source.php')
                else:
                    target.chmod(0o755)
                with self.assertRaisesRegex(policy.SeedError, 'UNREVIEWED_POLICY_DELTA'):
                    policy.validate_seed(self.root, self.commit('unsafe policy type'), core=self.core)

    def test_intermediate_or_merge_parent_cannot_replace_direct_core_parent(self):
        self.write('tests/test_go_erp_preview31_seed.py', '# later policy edit\n')
        later = self.commit('intermediate policy commit')
        with self.assertRaisesRegex(policy.SeedError, 'POLICY_PARENT_INVALID'):
            policy.validate_seed(self.root, later, core=self.core)
        tree = self.git('rev-parse', self.seed + '^{tree}').decode().strip()
        result = subprocess.run(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost',
            '-c', 'commit.gpgSign=false', '-C', str(self.root), 'commit-tree', tree,
            '-p', self.core, '-p', self.seed], input=b'merge policy\n',
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=policy.ENV, check=True)
        with self.assertRaisesRegex(policy.SeedError, 'POLICY_PARENT_INVALID'):
            policy.validate_seed(self.root, result.stdout.decode().strip(), core=self.core)

    def test_candidate_may_change_only_captured_source_version_and_metadata(self):
        revision, candidate = self.candidate()
        allowed = policy.validate_candidate_delta(self.root, revision, self.seed, candidate)
        self.assertEqual(allowed, {'app/source.php', policy.CANDIDATE, *policy.VERSION_FILES})
        for name in ['desktop-pos/src/local-runtime.cjs', 'desktop-pos/src/dashboard-download.cjs',
                     *policy.POLICY_FILES, 'deployment/verify_go_erp_current_candidate_ci.py',
                     'composer.lock', 'desktop-pos/runtime/composer.lock']:
            with self.subTest(name=name):
                revision, candidate = self.candidate(name)
                with self.assertRaisesRegex(policy.SeedError, 'FORBIDDEN_CANDIDATE_DELTA'):
                    policy.validate_candidate_delta(self.root, revision, self.seed, candidate)

    def test_candidate_cannot_claim_native_paths_as_captured_application(self):
        revision, candidate = self.candidate('desktop-pos/src/local-runtime.cjs')
        candidate['inventory']['desktop-pos/src/local-runtime.cjs'] = {'present': True}
        with self.assertRaisesRegex(policy.SeedError, 'CANDIDATE_INVENTORY_INVALID'):
            policy.validate_candidate_delta(self.root, revision, self.seed, candidate)
        with self.assertRaisesRegex(policy.SeedError, 'CANDIDATE_PARENT_INVALID'):
            policy.validate_candidate_delta(self.root, revision, self.core, candidate)

    def test_legacy_bases_delegate_without_entering_new_seed_policy(self):
        for base in policy.LEGACY_BASES:
            with self.subTest(base=base):
                self.write(policy.CANDIDATE, json.dumps({'baseRevision': base}))
                with patch.object(policy, 'legacy_verify', return_value={'legacy': base}) as legacy, \
                     patch.object(policy, 'validate_seed', side_effect=AssertionError('Legacy proof changed')):
                    self.assertEqual(policy.verify(self.root, self.root / 'historical'), {'legacy': base})
                    legacy.assert_called_once_with(self.root, self.root / 'historical')

    def test_new_candidate_runs_original_proof_against_exact_seed_and_preserves_checkout(self):
        revision, _ = self.candidate()
        original = types.SimpleNamespace(validate_checkout=lambda root, seed, expected_base:
            {'revision': revision, 'base_revision': expected_base, 'application_changed': False, 'database_read': False})
        baseline = self.git('worktree', 'list', '--porcelain')
        with patch.object(policy, 'CORE', self.core), \
             patch.object(policy, 'validate_seed', wraps=lambda root, seed: policy_validate(root, seed, self.core)), \
             patch.object(policy, 'original_verifier', return_value=original):
            result = policy.verify(self.root, self.root / 'historical')
        self.assertEqual(result['policy'], policy.POLICY)
        self.assertEqual(result['reviewed_native_import_core'], self.core)
        self.assertEqual(result['base_revision'], self.seed)
        self.assertEqual(self.git('rev-parse', 'HEAD').decode().strip(), revision)
        self.assertEqual(self.git('worktree', 'list', '--porcelain'), baseline)

    def test_forbidden_candidate_edit_is_rejected_before_loading_original_verifier(self):
        self.candidate('desktop-pos/src/local-runtime.cjs')
        with patch.object(policy, 'validate_seed', wraps=lambda root, seed: policy_validate(root, seed, self.core)), \
             patch.object(policy, 'original_verifier', side_effect=AssertionError('Forbidden code executed')):
            with self.assertRaisesRegex(policy.SeedError, 'FORBIDDEN_CANDIDATE_DELTA'):
                policy.verify(self.root, self.root / 'historical')

    def test_duplicate_metadata_and_invalid_revisions_are_refused(self):
        with self.assertRaisesRegex(policy.SeedError, 'DUPLICATE_JSON_FIELD'):
            policy.json_object(b'{"baseRevision":"one","baseRevision":"two"}')
        for revision in ('', 'foreign', True):
            with self.assertRaises(policy.SeedError):
                policy.validate_seed(self.root, revision, core=self.core)


policy_validate = policy.validate_seed


class FrozenSeedStageTests(unittest.TestCase):
    """Run the unchanged frozen staging functions and actual seed verifier.

    Git fetch uses this test's local repository instead of the public network.
    A minimal descriptor utility supplies the original function's filesystem
    primitives; seed/core checks and Git lineage validation remain unchanged.
    The pinned excerpts come from original base blob
    ede9af30ea42ba53a5376d5e1cf634d9b3032f2a.
    """
    setUp = Preview31SeedTests.setUp
    git = Preview31SeedTests.git
    write = Preview31SeedTests.write
    commit = Preview31SeedTests.commit
    policy_files = Preview31SeedTests.policy_files
    CHECKOUT = '''def checkout(root, revision, utility):
    directory = utility.Directory(root)
    internal = None
    try:
        internal = utility.Directory(root / '.git')
        need(git(root, 'rev-parse', 'HEAD').decode().strip() == revision
             and git(root, 'remote', 'get-url', 'origin').decode().strip() == REMOTE
             and not git(root, 'status', '--porcelain', '--untracked-files=all'), 'CHECKOUT_CHANGED')
        directory.verify(); internal.verify()
    finally:
        if internal is not None:
            internal.close()
        directory.close()
'''
    STAGE = '''def stage_seed(target, revision, core, utility):
    need(REV.fullmatch(revision) and REV.fullmatch(core), 'RELEASE_PINS_PENDING')
    git(target, 'init', '--quiet', '--template=')
    git(target, 'remote', 'add', 'origin', REMOTE)
    git(target, 'fetch', '--quiet', '--depth=32', '--no-tags', '--no-recurse-submodules', 'origin', revision)
    git(target, 'checkout', '--quiet', '--detach', '--no-recurse-submodules', revision)
    checkout(target, revision, utility)
    directory = utility.Directory(target / 'deployment')
    try:
        name = 'verify_go_erp_preview31_seed.py'
        pin = git(target, 'rev-parse', 'HEAD:deployment/' + name).decode().strip()
        # A root entry uses umask 077, so Git may create public source as 0600.
        # Normalize only this exact pinned verifier inside the private staging
        # tree; the reviewed module loader keeps its strict 0644 requirement.
        fd = os.open(name, os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory.fd)
        try:
            before = os.fstat(fd)
            mode = stat.S_IMODE(before.st_mode)
            need(mode in (0o600, 0o644), 'SEED_VERIFIER_MODE_INVALID')
            data = utility.read_fd(fd, utility.MAX_FILE, mode)
            need(blob(data) == pin and utility.exact_key(before) ==
                 utility.exact_key(os.stat(name, dir_fd=directory.fd, follow_symlinks=False)),
                 'SEED_VERIFIER_PIN_INVALID')
            if mode == 0o600:
                os.fchmod(fd, 0o644); os.fsync(fd); os.fsync(directory.fd)
        finally:
            os.close(fd)
        verifier = utility.module_at(directory, name, pin)
        need(getattr(verifier, 'CORE', None) == core, 'SEED_CORE_MISMATCH')
        result = verifier.validate_seed(target, revision, core=core)
        need(result.get('core') == core and result.get('seed') == revision, 'SEED_PROOF_INVALID')
    finally:
        directory.close()
'''

    def frozen_stage(self, revision, core):
        self.assertEqual(hashlib.sha256(self.CHECKOUT.encode()).hexdigest(),
                         '3b018d9c35c07e64f214b18a4ab95e0357fd71ffb77a89e63eeea9acfc6cf968')
        self.assertEqual(hashlib.sha256(self.STAGE.encode()).hexdigest(),
                         'ad1b59126102d44828b06310f360c2806a37f3ab6aadbdf20ba0b86c247328a4')
        def blob(raw):
            return hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()
        def key(info):
            return (info.st_dev,info.st_ino,info.st_mode,info.st_size,
                    info.st_mtime_ns,info.st_ctime_ns,info.st_nlink)
        def need(value, reason):
            if not value:raise RuntimeError(reason)
        class Directory:
            def __init__(scope,path):
                scope.path=Path(path)
                scope.fd=os.open(path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
                scope.identity=key(os.fstat(scope.fd))
            def verify(scope):
                need(scope.identity==key(os.fstat(scope.fd))==key(scope.path.lstat()),'DIRECTORY_CHANGED')
            def close(scope):os.close(scope.fd)
        def read_fd(fd,maximum,mode):
            need(stat.S_IMODE(os.fstat(fd).st_mode)==mode,'FILE_MODE_CHANGED')
            raw=os.read(fd,maximum+1)
            need(len(raw)<=maximum,'FILE_TOO_LARGE')
            return raw
        def module_at(directory,name,pin):
            fd=os.open(name,os.O_RDONLY|os.O_NOFOLLOW,dir_fd=directory.fd)
            try:raw=read_fd(fd,1024**2,0o644)
            finally:os.close(fd)
            need(blob(raw)==pin,'MODULE_BLOB_CHANGED')
            value=types.ModuleType('staged_real_policy');value.__file__=str(directory.path/name)
            exec(compile(raw,value.__file__,'exec'),value.__dict__)
            return value
        def staged_git(root,*arguments,**_kwargs):
            args=list(arguments)
            if args and args[0]=='fetch':
                self.assertEqual(args[-2],'origin')
                args[-2]=self.root.as_uri()
                args=['-c','protocol.file.allow=always',*args]
            return policy.git(root,*args)
        scope={'git':staged_git,'REMOTE':policy.REMOTE,'need':need,'REV':policy.REV,
               'os':os,'stat':stat,'blob':blob}
        exec(compile(self.CHECKOUT+self.STAGE,'<exact-frozen-seed-stage>','exec'),scope)
        utility=types.SimpleNamespace(Directory=Directory,read_fd=read_fd,MAX_FILE=1024**2,
                                     exact_key=key,module_at=module_at)
        with tempfile.TemporaryDirectory(prefix='go-erp-real-seed-stage-') as target:
            previous=os.umask(0o077)
            try:scope['stage_seed'](Path(target),revision,core,utility)
            finally:os.umask(previous)
            self.assertEqual(policy.git(target,'rev-parse','HEAD').decode().strip(),revision)
            self.assertEqual(stat.S_IMODE((Path(target)/'deployment/verify_go_erp_preview31_seed.py').stat().st_mode),0o644)

    def real_policy_seed(self, *, wrong_core=False, extra=False):
        self.git('reset','--hard',self.core)
        self.git('clean','-fd')
        verifier=Path(policy.__file__).read_text()
        if not wrong_core:
            anchor="CORE = '"+policy.CORE+"'"
            self.assertEqual(verifier.count(anchor),1)
            verifier=verifier.replace(anchor,"CORE = '"+self.core+"'")
        for name in policy.POLICY_FILES:
            self.write(name,verifier if name=='deployment/verify_go_erp_preview31_seed.py'
                       else '# exact permitted policy change '+name+'\n')
        if extra:self.write('desktop-pos/src/local-runtime.cjs','// forbidden policy native edit\n')
        return self.commit('stage the real immutable policy verifier')

    def test_real_frozen_stage_accepts_exact_policy_child_and_normalizes_verifier_mode(self):
        revision=self.real_policy_seed()
        self.frozen_stage(revision,self.core)

    def test_real_frozen_stage_rejects_mismatched_embedded_core(self):
        revision=self.real_policy_seed(wrong_core=True)
        with self.assertRaisesRegex(RuntimeError,'SEED_CORE_MISMATCH'):
            self.frozen_stage(revision,self.core)

    def test_real_frozen_stage_rejects_application_commit_used_as_policy_seed(self):
        revision=self.real_policy_seed(extra=True)
        with self.assertRaisesRegex(Exception,'UNREVIEWED_POLICY_DELTA'):
            self.frozen_stage(revision,self.core)


if __name__ == '__main__':
    unittest.main()
