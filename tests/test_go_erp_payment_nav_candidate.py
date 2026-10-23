"""Direct candidate guard with real local Git and independently exported metadata.

All five approved payloads are read from pinned local Git objects/assets. The
fixture seed/revisions and inventory are synthetic; no network/CI is involved.
"""
import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


class PaymentNavigationCandidateTests(unittest.TestCase):
    def test_real_local_git_guard_accepts_exact_five_files_and_rejects_drift(self):
        m = module('payment_nav_candidate_entry', ROOT / 'deployment/upgrade_go_erp_payment_nav_successor_root.py')
        p = module('payment_nav_candidate_prior', ROOT / 'deployment/nav-successor-prior/upgrade_go_erp_program_watcher_root.py')
        b = module('payment_nav_candidate_git', ROOT / 'deployment/import422-prior/upgrade_go_erp_preview31_watcher_root.py')
        source = module('payment_nav_candidate_actual_exporter', ROOT / 'deployment/wire-hash-prior/payload/go_erp_live_source.py')
        before, after = {}, {}
        for name, (old, wanted) in m.PAYMENT_PINS.items():
            before[name] = subprocess.check_output(['/usr/bin/git', '-C', str(ROOT), 'show', m.SEED + ':' + name])
            after[name] = subprocess.check_output(['/usr/bin/git', '-C', str(ROOT), 'show', m.PAYMENT_RELEASE + ':' + name])
            self.assertEqual(p.blob(before[name]), old)
            self.assertEqual(p.blob(after[name]), wanted)
        before[m.NAV] = (ROOT / m.NAV).read_bytes()
        after[m.NAV] = (ROOT / 'deployment/program-navigation/dashboard-navigation.js').read_bytes()
        self.assertEqual(p.blob(before[m.NAV]), m.BEFORE_NAV)
        self.assertEqual(p.blob(after[m.NAV]), m.AFTER_NAV)
        with tempfile.TemporaryDirectory(prefix='go-erp-payment-candidate-git-') as name:
            root = Path(name)
            repository, work = root / 'source', root / 'work'
            repository.mkdir(); work.mkdir()
            checkout = work / 'checkout'
            def git(*arguments):
                return subprocess.check_output(['/usr/bin/git', '-c', 'credential.helper=',
                    '-c', 'core.hooksPath=/dev/null', '-c', 'user.name=Protocol Fixture',
                    '-c', 'user.email=fixture@example.test', '-C', str(repository), *arguments],
                    stderr=subprocess.DEVNULL)
            def write(path, data):
                target = repository / path
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(data)
            def commit(message):
                git('add', '--all')
                git('commit', '--quiet', '--no-gpg-sign', '-m', message)
                return git('rev-parse', 'HEAD').decode().strip()
            git('init', '--quiet')
            for path, data in before.items(): write(path, data)
            package = {'name': 'synthetic-protocol-fixture', 'version': '0.4.4-preview.35'}
            lock = {**package, 'packages': {'': copy.deepcopy(package)}}
            write(p.VERSIONS[0], json.dumps(package).encode())
            write(p.VERSIONS[1], json.dumps(lock).encode())
            seed = commit('synthetic single-parent seed')
            subprocess.check_call(['/usr/bin/git', 'init', '--quiet', '--bare', str(checkout)])
            # Production checkout already contains its pinned seed. Keep it
            # present when candidate() fetches only the candidate at depth1.
            b.git(checkout, 'fetch', '--quiet', '--depth=1', str(repository), seed)
            inventory = {path: {'authority': 'live', 'present': True, 'mode': '100644',
                'bytes': len(data), 'sha256': m.digest(data)} for path, data in after.items()}
            snapshot = source._metadata(seed, inventory)
            metadata = source._candidate_metadata(snapshot, '0.4.4-preview.37', 37)
            self.assertNotIn('candidatePolicy', metadata)
            context = types.SimpleNamespace(base=b, prior=p)
            with mock.patch.object(m, 'WORK', work), mock.patch.object(m, 'SEED', seed), \
                 mock.patch.object(m, 'NEW_INVENTORY', snapshot['inventorySha256']), \
                 mock.patch.object(b, 'REMOTE', str(repository)):
                for fault, expected in [('valid', None), ('payment-blob', 'CANDIDATE_APPROVED_FILE_INVALID'),
                        ('extra-code', 'CANDIDATE_CODE_INVALID'), ('stale-metadata', 'CANDIDATE_METADATA_INVALID'),
                        ('stale-package', 'CANDIDATE_PACKAGE_INVALID')]:
                    git('checkout', '--quiet', '--detach', '--force', seed)
                    for path, data in after.items(): write(path, data)
                    current_package = {**package, 'version': '0.4.4-preview.37'}
                    current_lock = {**lock, 'version': '0.4.4-preview.37',
                                    'packages': {'': copy.deepcopy(current_package)}}
                    current_metadata = copy.deepcopy(metadata)
                    if fault == 'payment-blob': write(next(iter(m.PAYMENT_PINS)), b'<?php // unreviewed replacement\n')
                    elif fault == 'extra-code': write('app/Services/Unreviewed.php', b'<?php // unrelated code\n')
                    elif fault == 'stale-metadata': current_metadata['inventorySha256'] = m.OLD_INVENTORY
                    elif fault == 'stale-package': current_package['version'] = '0.4.4-preview.36'
                    write(p.VERSIONS[0], json.dumps(current_package).encode())
                    write(p.VERSIONS[1], json.dumps(current_lock).encode())
                    write(p.VERSIONS[2], json.dumps(current_metadata).encode())
                    revision = commit('synthetic candidate ' + fault)
                    with self.subTest(fault=fault):
                        if expected is None:
                            self.assertEqual(m.Context.candidate(context, revision, snapshot['inventorySha256'], 37), 37)
                        else:
                            with self.assertRaisesRegex(m.Stop, expected):
                                m.Context.candidate(context, revision, snapshot['inventorySha256'], 37)


if __name__ == '__main__': unittest.main()
