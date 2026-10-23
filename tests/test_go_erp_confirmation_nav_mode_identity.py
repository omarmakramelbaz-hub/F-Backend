"""Immutable payment/source derivation and actual local Git candidate checks.

The approved roster is independent of the successor implementation. Original
source and payment payloads come from pinned Git objects, never fixture strings
or the mutable checkout. Git fetches use a private local repository only.
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
from unittest import mock


ROOT = Path(__file__).resolve().parents[1]
SEED = 'cd35ee36d77bc35593cac3627a33307b71309325'
CANONICAL = 'dceb59ad489bbba6b6534a4da784f6d26c82b2ff'
CONFIRMATION = '31d4020a9195904e1578bd6207f5dc805aa1541d'
DELIVERY = '59d4fd2466137ee5c31a9f4701d3f5bc024a6ea3'
CORE = 'd3cef0a0f823d1f10814ff74ce0f4badf5c29670'
ACTUAL_37 = '5f2b0fecafec1c3803499284b0abcca4ecfed7e4'
ACTUAL_METADATA_BLOB = '072014bf37391223fd7f700a1a59c3178a57901d'
NAV = 'public/dashboard/js/dashboard-navigation.js'
VERSIONS = ('desktop-pos/package.json', 'desktop-pos/package-lock.json',
            'deployment/go_erp_live_source_candidate.json')
LATER = (
    'app/Http/Controllers/CheckoutController.php',
    'app/Http/Controllers/Payment/PaymobController.php',
    'app/Http/Controllers/Payment/RestaurantPaymentController.php',
    'app/Services/Payments/RestaurantOrderPayment.php',
    'app/Services/Payments/RestaurantPaymentEffects.php',
    'app/Console/Commands/ReconcileRestaurantPayments.php',
    'app/Console/Kernel.php',
    'app/Services/Dashboard/OrderBoardService.php',
    'routes/api.php',
    'resources/views/admin/orders/board_card.blade.php',
    'resources/views/admin/orders/board_detail.blade.php',
    'resources/lang/ar/order_board.php',
    'resources/lang/en/order_board.php',
    'public/dashboard/css/order-board.css',
)
EARLIER_ONLY = (
    'app/Services/Dashboard/PosServiceTicket.php',
    'public/dashboard/js/phone-orders.js',
    'public/dashboard/css/phone-delivery-refresh.css',
)


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


def git(*arguments, cwd=ROOT, input=None, env=None):
    return subprocess.check_output(['/usr/bin/git', '-c', 'credential.helper=',
        '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false',
        '-c', 'user.name=Protocol Fixture', '-c', 'user.email=fixture@example.test',
        '-C', str(cwd), *arguments], input=input, env=env, stderr=subprocess.PIPE)


def immutable(revision, path):
    return git('show', revision + ':' + path)


def immutable_mode(revision, path):
    row = git('ls-tree', revision, '--', path).split(b'\t', 1)
    if len(row) != 2 or row[1].rstrip(b'\n').decode() != path:
        raise AssertionError('Pinned path is absent from its immutable tree: ' + path)
    return row[0].split()[0].decode()


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def canonical(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True,
                      separators=(',', ':'), allow_nan=False).encode()


def inventory_hash(value):
    return hashlib.sha256(canonical(value)).hexdigest()


def source_hash(files):
    result = hashlib.sha256(b'desktop-dashboard-source:1\n')
    for path, data in sorted(files.items()):
        digest = hashlib.sha256(data.replace(b'\r\n', b'\n')).hexdigest()
        result.update(path.encode() + b'\0' + digest.encode() + b'\n')
    return {'format': 1, 'files': len(files), 'sha256': result.hexdigest(),
            'framework': '8.83.29'}


class ConfirmationNavigationModeIdentityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.legacy = load('confirmation_nav_mode_identity_historical_entry', ROOT /
                        'deployment/upgrade_go_erp_confirmation_nav_successor_root.py')
        cls.entry = load('confirmation_nav_mode_identity_entry', ROOT /
                        'deployment/upgrade_go_erp_mode_inventory_root.py')
        cls.prior = load('confirmation_nav_mode_identity_prior', ROOT /
                        'deployment/nav-successor-prior/upgrade_go_erp_program_watcher_root.py')
        cls.base = load('confirmation_nav_mode_identity_git', ROOT /
                       'deployment/import422-prior/upgrade_go_erp_preview31_watcher_root.py')
        cls.exporter = load('confirmation_nav_mode_identity_exporter', ROOT /
                           'deployment/wire-hash-prior/payload/go_erp_live_source.py')
        cls.scanner = load('confirmation_nav_mode_identity_scanner', ROOT /
                          'deployment/refresh_desktop_dashboard_server_manifest.py')
        cls.approved = {path: immutable(CONFIRMATION, path) for path in LATER}
        cls.approved.update({path: immutable(DELIVERY, path) for path in EARLIER_ONLY})
        cls.approved_modes = {path: immutable_mode(CONFIRMATION, path) for path in LATER}
        cls.approved_modes.update({path: immutable_mode(DELIVERY, path) for path in EARLIER_ONLY})
        cls.nav = (ROOT / 'deployment/program-navigation/dashboard-navigation.js').read_bytes()
        cls.original_metadata = json.loads(immutable(CANONICAL, VERSIONS[2]))
        cls.before_inventory = copy.deepcopy(cls.original_metadata['inventory'])
        for path, data in cls.approved.items():
            cls.before_inventory[path] = {'authority': 'live', 'present': True,
                'mode': cls.approved_modes[path], 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
        cls.after_inventory = copy.deepcopy(cls.before_inventory)
        cls.after_inventory[NAV] = {'authority': 'live', 'present': True,
            'mode': '100644', 'bytes': len(cls.nav), 'sha256': hashlib.sha256(cls.nav).hexdigest()}

    def test_canonical_36_and_frozen_core_remain_exact(self):
        m = self.legacy
        self.assertEqual((self.entry.CORE, self.entry.SEED), (CORE, SEED))
        self.assertEqual((m.CORE, m.SEED, m.OLD_CANDIDATE), (CORE, SEED, CANONICAL))
        self.assertEqual((m.PAYMENT_RELEASE, m.DELIVERY_RELEASE), (CONFIRMATION, DELIVERY))
        header = git('cat-file', 'commit', CANONICAL).split(b'\n\n', 1)[0]
        self.assertEqual([line[7:].decode() for line in header.splitlines()
                          if line.startswith(b'parent ')], [SEED])
        changed = set(git('diff', '--name-only', '--no-renames', '-z', SEED,
                          CANONICAL, '--').decode().split('\0')) - {''}
        self.assertEqual(changed, set(VERSIONS))
        for path in ('.github/workflows/desktop-dashboard-tests.yml',
                     'tests/desktop_dashboard_runtime/browser.cjs',
                     'tests/desktop_dashboard_runtime/legacy.php',
                     'desktop-pos/src/local-runtime.cjs'):
            self.assertEqual(immutable(SEED, path), immutable(CORE, path), path)
        self.assertEqual(self.original_metadata['inventorySha256'], m.OLD_INVENTORY)
        self.assertEqual(inventory_hash(self.original_metadata['inventory']), m.OLD_INVENTORY)
        self.assertNotIn('candidatePolicy', self.original_metadata)

    def test_exact_17_approved_git_blobs_and_derived_complete_source(self):
        m = self.legacy
        self.assertEqual(len(self.approved), 17)
        self.assertEqual(set(m.PAYMENT_PINS), set(LATER) | set(EARLIER_ONLY))
        self.assertEqual(self.entry.APPROVED_PINS, m.PAYMENT_PINS)
        tree = {}
        for line in git('ls-tree', '-r', '-z', SEED).split(b'\0'):
            if not line:
                continue
            meta, raw_path = line.split(b'\t', 1)
            mode, kind, object_id = meta.split()
            tree[raw_path.decode()] = (mode.decode(), kind.decode(), object_id.decode())
        for path, data in self.approved.items():
            old = tree.get(path)
            self.assertEqual(m.PAYMENT_PINS[path], (old[2] if old else None, blob(data)), path)
            self.assertTrue(self.scanner.selected(path), path)
            if old:
                self.assertEqual(self.approved_modes[path], old[0], path)
        # These modes are part of the approved source inventory. Normalizing
        # both controllers to 100644 invents a different release identity even
        # when all source bytes and the normalized application hash match.
        executable = {path for path, mode in self.approved_modes.items() if mode == '100755'}
        self.assertEqual(executable, {'app/Http/Controllers/CheckoutController.php',
                                     'app/Http/Controllers/Payment/PaymobController.php'})
        # Stream every selected immutable source blob. This independently proves
        # the full fingerprint, including unchanged files and four additions.
        selected = {path: item for path, item in tree.items() if self.scanner.selected(path)}
        objects = b''.join((row[2] + '\n').encode() for row in selected.values())
        raw = git('cat-file', '--batch', input=objects)
        files, position = {}, 0
        for path, (mode, kind, object_id) in selected.items():
            end = raw.index(b'\n', position)
            header = raw[position:end].split()
            self.assertEqual(header[:2], [object_id.encode(), b'blob'], path)
            length = int(header[2]); position = end + 1
            files[path] = raw[position:position + length]
            self.assertEqual(blob(files[path]), object_id, path)
            position += length + 1
        self.assertEqual(position, len(raw))
        self.assertEqual(source_hash(files), m.OLD_SOURCE)
        files.update(self.approved)
        self.assertEqual(source_hash(files), m.BEFORE_SOURCE)
        self.assertEqual(source_hash(files)['sha256'],
                         '5825ec04b927fcafc42e04bd9ee4705a2e1862bb62be700a116502eacad60a1e')
        self.assertEqual(len(self.before_inventory), 2558)
        self.assertEqual(inventory_hash(self.before_inventory),
                         'fddb71ab6baa4a13292816355e463054dbcca600135ce132cfaa36e0eab2faef')
        files[NAV] = self.nav
        self.assertEqual(source_hash(files), m.NEW_SOURCE)
        self.assertEqual(source_hash(files)['sha256'],
                         '41172badb00910203115a45b26c695d47757ee1cc4ac26d07ba556a7f657ea63')
        self.assertEqual(source_hash(files), self.entry.SOURCE)
        self.assertEqual(inventory_hash(self.after_inventory), self.entry.TARGET_INVENTORY)
        self.assertEqual(self.entry.TARGET_INVENTORY,
                         'de5ac407604249df2198aa71c2d7b4ca0a88ac16bba3cf3f028c61e3077111d8')
        normalized_modes = copy.deepcopy(self.after_inventory)
        for path in executable:
            normalized_modes[path]['mode'] = '100644'
        self.assertEqual(inventory_hash(normalized_modes),
                         '1480586d09f7b87ac2528f74b14a5694c631d31c6ddc5c32eb8dc750c5550a9c')
        self.assertEqual(inventory_hash(normalized_modes), self.entry.PREPARED_INVENTORY)
        self.assertNotEqual(inventory_hash(normalized_modes), self.entry.TARGET_INVENTORY)
        self.assertEqual(m.PREPARED_OVERRIDES, {'routes/api.php': blob(self.approved['routes/api.php'])})

    def test_actual_37_has_exact_approved_tree_and_full_metadata(self):
        self.assertEqual(self.entry.CANDIDATE, ACTUAL_37)
        header = git('cat-file', 'commit', ACTUAL_37).split(b'\n\n', 1)[0]
        self.assertEqual([line[7:].decode() for line in header.splitlines()
                          if line.startswith(b'parent ')], [SEED])
        changed = set(git('diff', '--name-only', '--no-renames', '-z', SEED,
                          ACTUAL_37, '--').decode().split('\0')) - {''}
        self.assertEqual(len(changed), 21)
        self.assertEqual(changed, set(self.approved) | {NAV} | set(VERSIONS))
        for path, data in {**self.approved, NAV: self.nav}.items():
            self.assertEqual(immutable(ACTUAL_37, path), data, path)
            self.assertEqual(immutable_mode(ACTUAL_37, path),
                             self.approved_modes.get(path, '100644'), path)
        data = immutable(ACTUAL_37, VERSIONS[2])
        self.assertEqual(blob(data), ACTUAL_METADATA_BLOB)
        metadata = json.loads(data)
        snapshot = self.exporter._metadata(SEED, self.after_inventory)
        expected = self.exporter._candidate_metadata(snapshot, '0.4.4-preview.37', 37)
        self.assertEqual(metadata, expected)
        self.assertEqual(metadata['inventory'], self.after_inventory)
        self.assertEqual(metadata['inventorySha256'], self.entry.TARGET_INVENTORY)
        for path in VERSIONS[:2]:
            old = json.loads(immutable(SEED, path))
            old['version'] = '0.4.4-preview.37'
            if path.endswith('package-lock.json'):
                old['packages']['']['version'] = old['version']
            self.assertEqual(json.loads(immutable(ACTUAL_37, path)), old, path)
        for path in VERSIONS:
            self.assertEqual(immutable_mode(ACTUAL_37, path), immutable_mode(SEED, path), path)

    def test_real_git_guard_rejects_byte_mode_version_and_policy_drift(self):
        m, b, p = self.entry, self.base, self.prior
        with tempfile.TemporaryDirectory(prefix='confirmation-nav-identity-') as temporary:
            root = Path(temporary)
            remote, work = root / 'remote.git', root / 'work'
            work.mkdir()
            subprocess.check_call(['/usr/bin/git', 'init', '--quiet', '--bare', str(remote)])
            subprocess.check_call(['/usr/bin/git', 'init', '--quiet', '--bare', str(work / 'checkout')])
            # The fixture is a private writable object store with read-only
            # access to the exact seed/canonical objects. No external fetches.
            object_dir = Path(git('rev-parse', '--git-common-dir').decode().strip())
            if not object_dir.is_absolute():
                object_dir = (ROOT / object_dir).resolve()
            (remote / 'objects/info/alternates').write_text(str(object_dir / 'objects') + '\n')
            b.git(work / 'checkout', 'fetch', '--quiet', '--depth=1', str(remote), SEED)
            snapshot = self.exporter._metadata(SEED, self.after_inventory)
            metadata = self.exporter._candidate_metadata(snapshot, '0.4.4-preview.37', 37)
            self.assertNotIn('candidatePolicy', metadata)
            env = {**os.environ, 'GIT_INDEX_FILE': str(root / 'index'),
                   'GIT_AUTHOR_NAME': 'Protocol Fixture', 'GIT_AUTHOR_EMAIL': 'fixture@example.test',
                   'GIT_COMMITTER_NAME': 'Protocol Fixture', 'GIT_COMMITTER_EMAIL': 'fixture@example.test'}
            def write(path, data, mode='100644'):
                object_id = git('hash-object', '-w', '--stdin', cwd=remote, input=data).decode().strip()
                git('update-index', '--add', '--cacheinfo', mode + ',' + object_id + ',' + path,
                    cwd=remote, env=env)
            def candidate(fault=None):
                git('read-tree', SEED, cwd=remote, env=env)
                for path, data in {**self.approved, NAV: self.nav}.items():
                    mode = self.approved_modes.get(path, '100644')
                    if fault == ('mode', path):
                        mode = '100644' if mode == '100755' else '100755'
                    write(path, data if fault != ('payload', path) else data + b'\n// unreviewed\n',
                          mode)
                current = copy.deepcopy(metadata)
                package = json.loads(immutable(SEED, VERSIONS[0]))
                lock = json.loads(immutable(SEED, VERSIONS[1]))
                package['version'] = lock['version'] = lock['packages']['']['version'] = '0.4.4-preview.37'
                if fault == 'metadata':
                    current['inventorySha256'] = m.PREPARED_INVENTORY
                elif fault == 'metadata-policy':
                    current['candidatePolicy'] = 'unreviewed-policy'
                elif fault == 'version':
                    current = self.exporter._candidate_metadata(snapshot, '0.4.4-preview.36', 36)
                    package['version'] = lock['version'] = lock['packages']['']['version'] = '0.4.4-preview.36'
                elif fault == 'package':
                    package['version'] = '0.4.4-preview.36'
                elif isinstance(fault, tuple) and fault[0] == 'extra':
                    write(fault[1], b'// unrelated unreviewed change\n')
                for path, value in zip(VERSIONS, (package, lock, current)):
                    write(path, canonical(value) + b'\n', '100755' if fault == ('version-mode', path) else '100644')
                tree = git('write-tree', cwd=remote, env=env).decode().strip()
                parent = CANONICAL if fault == 'parent' else SEED
                return git('commit-tree', tree, '-p', parent, cwd=remote,
                           input=b'private candidate fixture\n', env=env).decode().strip()
            context = types.SimpleNamespace(base=b, prior=p)
            with mock.patch.object(m, 'WORK', work), mock.patch.object(b, 'REMOTE', str(remote)):
                def inspect(revision):
                    # The bounded reconciler never fetches. Populate its
                    # already prepared private checkout explicitly, then run
                    # the production read-only proof on those real Git objects.
                    b.git(work / 'checkout', 'fetch', '--quiet', '--depth=1', str(remote), revision)
                    return m.Context.candidate(context, revision, m.TARGET_INVENTORY, 37)
                valid = candidate()
                self.assertEqual(inspect(ACTUAL_37), 37)
                self.assertEqual(inspect(valid), 37)
                faults = [(('payload', path), 'CANDIDATE_APPROVED_FILE_INVALID')
                          for path in (*LATER, *EARLIER_ONLY, NAV)]
                faults += [
                    (('mode', 'app/Http/Controllers/CheckoutController.php'), 'CANDIDATE_APPROVED_FILE_INVALID'),
                    (('mode', 'app/Http/Controllers/Payment/PaymobController.php'), 'CANDIDATE_APPROVED_FILE_INVALID'),
                    (('mode', NAV), 'CANDIDATE_APPROVED_FILE_INVALID'),
                    (('version-mode', VERSIONS[0]), 'CANDIDATE_PACKAGE_INVALID'),
                    (('version-mode', VERSIONS[1]), 'CANDIDATE_PACKAGE_INVALID'),
                    (('version-mode', VERSIONS[2]), 'CANDIDATE_PACKAGE_INVALID'),
                    (('extra', 'app/Services/Unreviewed.php'), 'CANDIDATE_CODE_INVALID'),
                    (('extra', '.github/workflows/desktop-dashboard-tests.yml'), 'CANDIDATE_CODE_INVALID'),
                    (('extra', 'desktop-pos/src/local-runtime.cjs'), 'CANDIDATE_CODE_INVALID'),
                    ('metadata', 'CANDIDATE_METADATA_INVALID'),
                    ('metadata-policy', 'CANDIDATE_METADATA_INVALID'),
                    ('package', 'CANDIDATE_PACKAGE_INVALID'),
                    ('version', 'CANDIDATE_VERSION_INVALID|CANDIDATE_METADATA_INVALID'),
                    ('parent', 'CANDIDATE_PARENT_INVALID'),
                ]
                for fault, reason in faults:
                    with self.subTest(fault=fault):
                        revision = candidate(fault)
                        with self.assertRaisesRegex(m.Stop, reason):
                            inspect(revision)
                # Building rejected candidates cannot mutate either original identity.
                self.assertEqual(git('rev-parse', CANONICAL).decode().strip(), CANONICAL)
                self.assertEqual(immutable(CANONICAL, VERSIONS[2]), canonical(self.original_metadata) + b'\n')


if __name__ == '__main__':
    unittest.main()
