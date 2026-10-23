"""Real Git proof for Preview 38 against the fixture-only successor seed.

Reuses the independent immutable payment roster and Windows core/seed proofs.
No application, account, remote branch or installed watcher is modified.
"""
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import types
import unittest


ROOT = Path(__file__).resolve().parents[1]
CORE = '4e0c33197b6d9cd635e3396e1edbf567bfb3a020'
SEED = 'e64e69667198b7dceddd4dfe516d963f43bbae27'
SOURCE = {'format': 1, 'files': 1346, 'framework': '8.83.29',
          'sha256': '41172badb00910203115a45b26c695d47757ee1cc4ac26d07ba556a7f657ea63'}
INVENTORY = 'de5ac407604249df2198aa71c2d7b4ca0a88ac16bba3cf3f028c61e3077111d8'


def load(name, relative):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


class FixtureSeedCandidateIdentityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.proof = load('fixture_seed_payment_identity_helpers', 'tests/test_go_erp_confirmation_nav_mode_identity.py')
        cls.entry = load('fixture_seed_candidate_identity_entry', 'deployment/upgrade_go_erp_fixture_seed_root.py')
        cls.base = load('fixture_seed_candidate_git', 'deployment/import422-prior/upgrade_go_erp_preview31_watcher_root.py')
        cls.prior = load('fixture_seed_candidate_prior', 'deployment/nav-successor-prior/upgrade_go_erp_program_watcher_root.py')
        cls.mode = load('fixture_seed_candidate_approved_roster', 'deployment/upgrade_go_erp_mode_inventory_root.py')
        cls.exporter = load('fixture_seed_actual_source_exporter', 'deployment/wire-hash-prior/payload/go_erp_live_source.py')
        h = cls.proof
        cls.approved = {name: h.immutable(h.CONFIRMATION, name) for name in h.LATER}
        cls.approved.update({name: h.immutable(h.DELIVERY, name) for name in h.EARLIER_ONLY})
        cls.modes = {name: h.immutable_mode(h.CONFIRMATION, name) for name in h.LATER}
        cls.modes.update({name: h.immutable_mode(h.DELIVERY, name) for name in h.EARLIER_ONLY})
        cls.nav = (ROOT / 'deployment/program-navigation/dashboard-navigation.js').read_bytes()
        cls.rows = copy.deepcopy(json.loads(h.immutable(h.CANONICAL, h.VERSIONS[2]))['inventory'])
        for name, data in {**cls.approved, h.NAV: cls.nav}.items():
            cls.rows[name] = {'authority': 'live', 'present': True,
                'mode': cls.modes.get(name, '100644'), 'bytes': len(data),
                'sha256': hashlib.sha256(data).hexdigest()}

    def test_successor_keeps_the_complete_approved_source_inventory(self):
        h, m = self.proof, self.entry
        self.assertEqual((m.CORE, m.SEED, m.SOURCE, m.INVENTORY, m.VERSION),
                         (CORE, SEED, SOURCE, INVENTORY, '0.4.4-preview.38'))
        self.assertEqual(len(self.rows), 2558)
        self.assertEqual(h.inventory_hash(self.rows), INVENTORY)
        old = json.loads(h.immutable(h.ACTUAL_37, h.VERSIONS[2]))
        self.assertEqual(self.rows, old['inventory'])
        snapshot = self.exporter._metadata(SEED, self.rows)
        metadata = self.exporter._candidate_metadata(snapshot, m.VERSION, 38)
        self.assertEqual(metadata, self.prior.candidate_metadata(SEED, self.rows, 38))
        self.assertNotIn('candidatePolicy', metadata)
        self.assertEqual(metadata['inventorySha256'], old['inventorySha256'])
        self.assertEqual(metadata['inventory'], old['inventory'])
        self.assertEqual(metadata['baseRevision'], SEED)
        self.assertEqual(metadata['versionSequence'], 38)
        self.assertNotEqual(metadata['baseRevision'], old['baseRevision'])
        for name in ('desktop-pos/src/dashboard-media.cjs', 'desktop-pos/test/generation-native.cjs',
                     'desktop-pos/test/media.test.cjs', 'desktop-pos/test/media-reuse.test.cjs'):
            self.assertNotIn(name, self.rows, 'Fixture/native lineage changes cannot alter application inventory')
        for name, data in self.approved.items():
            self.assertEqual(self.mode.APPROVED_PINS[name][1], h.blob(data), name)
        self.assertEqual(set(self.mode.APPROVED_PINS), set(self.approved))

    def test_real_git_candidate_guard_requires_exact_payload_modes_seed_and_version(self):
        h, m = self.proof, self.entry
        with tempfile.TemporaryDirectory(prefix='fixture-seed-candidate-git-') as temporary:
            root = Path(temporary)
            repository, package = root / 'release.git', root / 'package'
            package.mkdir()
            subprocess.check_call(['/usr/bin/git', 'init', '--quiet', '--bare', str(repository)])
            common = Path(h.git('rev-parse', '--git-common-dir').decode().strip())
            if not common.is_absolute():
                common = (ROOT / common).resolve()
            (repository / 'objects/info/alternates').write_text(str(common / 'objects') + '\n')
            env = {**os.environ, 'GIT_INDEX_FILE': str(root / 'candidate-index'),
                   'GIT_AUTHOR_NAME': 'Protocol Fixture', 'GIT_AUTHOR_EMAIL': 'fixture@example.test',
                   'GIT_COMMITTER_NAME': 'Protocol Fixture', 'GIT_COMMITTER_EMAIL': 'fixture@example.test'}
            snapshot = self.exporter._metadata(SEED, self.rows)
            metadata = self.exporter._candidate_metadata(snapshot, m.VERSION, 38)
            def write(name, data, mode='100644'):
                identifier = h.git('hash-object', '-w', '--stdin', cwd=repository, input=data).decode().strip()
                h.git('update-index', '--add', '--cacheinfo', mode + ',' + identifier + ',' + name,
                      cwd=repository, env=env)
            def candidate(fault=None):
                h.git('read-tree', SEED, cwd=repository, env=env)
                for name, data in {**self.approved, h.NAV: self.nav}.items():
                    mode = self.modes.get(name, '100644')
                    if fault == ('mode', name):
                        mode = '100644' if mode == '100755' else '100755'
                    if fault == ('payload', name):
                        data += b'\n// unreviewed replacement\n'
                    write(name, data, mode)
                current = copy.deepcopy(metadata)
                manifest = json.loads(h.immutable(SEED, h.VERSIONS[0]))
                lock = json.loads(h.immutable(SEED, h.VERSIONS[1]))
                manifest['version'] = lock['version'] = lock['packages']['']['version'] = m.VERSION
                if fault == 'inventory':
                    current['inventorySha256'] = self.mode.PREPARED_INVENTORY
                elif fault == 'policy':
                    current['candidatePolicy'] = 'unreviewed-policy'
                elif fault == 'version':
                    current = self.exporter._candidate_metadata(snapshot, '0.4.4-preview.37', 37)
                    manifest['version'] = lock['version'] = lock['packages']['']['version'] = '0.4.4-preview.37'
                elif fault == 'package':
                    manifest['version'] = '0.4.4-preview.37'
                elif isinstance(fault, tuple) and fault[0] == 'extra':
                    write(fault[1], b'// unrelated unreviewed change\n')
                for name, value in zip(h.VERSIONS, (manifest, lock, current)):
                    write(name, h.canonical(value) + b'\n', '100755' if fault == ('version-mode', name) else '100644')
                tree = h.git('write-tree', cwd=repository, env=env).decode().strip()
                parent = h.SEED if fault == 'parent' else SEED
                return h.git('commit-tree', tree, '-p', parent, cwd=repository,
                             input=b'private candidate fixture\n', env=env).decode().strip()
            context = types.SimpleNamespace(package=package, base=self.base, prior=self.prior, mode=self.mode)
            before_refs = h.git('show-ref')
            valid = candidate()
            self.assertEqual(m.Context.candidate(context, valid), metadata)
            faults = [(('payload', name), 'CANDIDATE_APPROVED_FILE_INVALID')
                      for name in (*h.LATER, *h.EARLIER_ONLY, h.NAV)]
            faults += [
                (('mode', 'app/Http/Controllers/CheckoutController.php'), 'CANDIDATE_APPROVED_FILE_INVALID'),
                (('mode', 'app/Http/Controllers/Payment/PaymobController.php'), 'CANDIDATE_APPROVED_FILE_INVALID'),
                (('mode', h.NAV), 'CANDIDATE_APPROVED_FILE_INVALID'),
                *((('version-mode', name), 'CANDIDATE_PACKAGE_INVALID') for name in h.VERSIONS),
                (('extra', 'app/Services/Unreviewed.php'), 'CANDIDATE_CODE_INVALID'),
                (('extra', '.github/workflows/desktop-dashboard-tests.yml'), 'CANDIDATE_CODE_INVALID'),
                (('extra', 'desktop-pos/src/dashboard-media.cjs'), 'CANDIDATE_CODE_INVALID'),
                ('inventory', 'CANDIDATE_METADATA_INVALID'),
                ('policy', 'CANDIDATE_METADATA_INVALID'),
                ('package', 'CANDIDATE_PACKAGE_INVALID'),
                ('version', 'CANDIDATE_METADATA_INVALID'),
                ('parent', 'CANDIDATE_PARENT_INVALID'),
            ]
            for fault, reason in faults:
                with self.subTest(fault=fault):
                    revision = candidate(fault)
                    with self.assertRaisesRegex(m.Stop, reason):
                        m.Context.candidate(context, revision)
            self.assertEqual(h.git('show-ref'), before_refs, 'Private proof must never update a release ref')


if __name__ == '__main__':
    unittest.main()
