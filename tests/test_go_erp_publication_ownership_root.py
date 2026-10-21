import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest import mock

REPOSITORY = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('owner_recovery', REPOSITORY / 'deployment' / 'repair_go_erp_publication_ownership_root.py')
owner = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(owner)
RECEIPT = REPOSITORY / 'tests' / 'fixtures' / 'go-erp-publication-permissions-20261021-174908.json'


def local_git(arguments, maximum=2 * 1024 * 1024):
    value = subprocess.check_output(['/usr/bin/git', '-C', str(REPOSITORY)] + list(arguments))
    if len(value) > maximum:
        raise AssertionError('fixture output exceeded reviewed bound')
    return value


class VirtualOwnership:
    """Explicit UID0-only local adapter. CI requires real POSIX ownership."""
    def __init__(self):
        self.values = {}
        self.stat, self.lstat, self.fstat = os.stat, os.lstat, os.fstat

    def view(self, value):
        pair = self.values.get((value.st_dev, value.st_ino))
        if pair is None:
            return value
        class View:
            st_uid, st_gid = pair
            def __getattr__(self, name):
                return getattr(value, name)
            def __getitem__(self, index):
                return pair[index - 4] if index in (4, 5) else value[index]
        return View()

    def chown(self, path, uid, gid, **kwargs):
        value = self.stat(path, **kwargs)
        self.values[(value.st_dev, value.st_ino)] = (uid, gid)

    def fchown(self, fd, uid, gid):
        value = self.fstat(fd)
        self.values[(value.st_dev, value.st_ino)] = (uid, gid)

    def patches(self):
        return [mock.patch.object(os, 'stat', lambda *a, **k: self.view(self.stat(*a, **k))),
                mock.patch.object(os, 'lstat', lambda *a, **k: self.view(self.lstat(*a, **k))),
                mock.patch.object(os, 'fstat', lambda *a, **k: self.view(self.fstat(*a, **k))),
                mock.patch.object(os, 'chown', self.chown), mock.patch.object(os, 'fchown', self.fchown)]


@unittest.skipUnless(os.geteuid() == 0, 'Dedicated root ownership fixtures required.')
class PublicationOwnerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.context = owner.reviewed_context(local_git)
        cls.receipt = json.loads(RECEIPT.read_text())

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-publication-owner-test-')
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.root = self.base / 'application'
        self.root.mkdir()
        uid_map = Path('/proc/self/uid_map').read_text().split()
        self.virtual = len(uid_map) == 3 and uid_map[-1] == '1'
        if self.virtual:
            if os.environ.get('GO_ERP_TEST_REQUIRE_REAL_CHOWN') == '1':
                self.fail('CI requires real ownership; this namespace maps only UID0')
            adapter = VirtualOwnership()
            for patch in adapter.patches():
                patch.start()
                self.addCleanup(patch.stop)
        for name, (pin, mode) in owner.COHORT.items():
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(local_git(['cat-file', 'blob', pin]))
            path.chmod(mode)
        self.extra = self.root / 'app' / 'unlisted.php'
        self.extra.write_bytes(b'<?php /* protected fixture bytes */\n')
        for folder, _dirs, _files in os.walk(str(self.root)):
            os.chown(folder, owner.UID, owner.GID)
        os.chown(str(self.extra), owner.UID, owner.GID)
        self.state = 'BEFORE'
        self.calls = 0
        self.after_read = None
        self.audit_parent = self.base / 'audits'

    def scan(self, root, context, head_reader, cohort):
        self.calls += 1
        scanner, source = context['scanner'], context['source']
        snapshot = scanner['snapshot'](root, source, [], [], head_reader)
        report = copy.deepcopy(self.receipt)
        report['unsafe_publication_paths'] = [row for row in report['unsafe_publication_paths'] if row['issues'] != ['owner_mismatch']]
        if self.state == 'AFTER':
            report['source_matches_reviewed_before'] = False
            report['unsafe_publication_paths'] = []
        for name in cohort:
            item = (root / name).lstat()
            if item.st_uid != owner.UID:
                report['unsafe_publication_paths'].append({'path': name, 'uid': item.st_uid, 'gid': item.st_gid,
                    'mode': format(stat.S_IMODE(item.st_mode), '04o'), 'links': item.st_nlink,
                    'before_blob_matches': True, 'issues': ['owner_mismatch']})
        if self.extra.stat().st_uid != owner.UID:
            report['unsafe_publication_paths'].append({'path': 'app/unlisted.php', 'uid': 0, 'gid': 0,
                'mode': '0644', 'links': 1, 'before_blob_matches': True, 'issues': ['owner_mismatch']})
        owner.check_audit(report, self.state, cohort)
        result = {'state': self.state, 'snapshot': snapshot,
                  'observed': copy.deepcopy(snapshot['metadata']), 'head': head_reader()}
        if self.after_read:
            self.after_read(self.calls)
        return result

    def run_recovery(self, requested=True):
        return owner.recover(self.root, self.context, lambda: 'e' * 40, requested, owner.State(),
            inspector=self.scan, audit_parent=self.audit_parent)

    def target_invariants(self):
        return {name: (owner.invariant((self.root / name).stat()), (self.root / name).read_bytes()) for name in owner.COHORT}

    def test_measured_receipt_exact_eight_modes_and_pins(self):
        measured = {row['path']: int(row['mode'], 8) for row in self.receipt['unsafe_publication_paths'] if row['issues'] == ['owner_mismatch']}
        self.assertEqual(measured, {name: mode for name, (_pin, mode) in owner.COHORT.items()})
        self.assertEqual(len(measured), 8)
        for name, (pin, _mode) in owner.COHORT.items():
            self.assertEqual(self.context['by_path'][name]['before_blob'], pin)
        self.assertEqual(sum(row['issues'] == ['group_or_other_writable'] for row in self.receipt['unsafe_publication_paths']), 44)
        owner.check_audit(self.receipt, 'BEFORE')

    def test_real_descriptors_preserve_bytes_modes_mtime_and_private_audit(self):
        original = self.target_invariants()
        extra = self.extra.read_bytes(), owner.identity(self.extra.stat())
        result = self.run_recovery()
        self.assertEqual(result['changed'], 8)
        self.assertEqual(self.target_invariants(), original)
        self.assertTrue(all(((self.root / name).stat().st_uid, (self.root / name).stat().st_gid) == (owner.UID, owner.GID) for name in owner.COHORT))
        self.assertEqual((self.extra.read_bytes(), owner.identity(self.extra.stat())), extra)
        audit = Path(result['audit'])
        self.assertEqual(stat.S_IMODE(audit.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE((audit / 'planned.json').stat().st_mode), 0o600)
        plans = json.loads((audit / 'planned.json').read_text())['plans']
        self.assertEqual(len(plans), 8)
        self.assertTrue(all(row['before_uid'] == 0 and row['before_gid'] == 0 for row in plans))
        self.assertTrue((audit / 'completed.json').is_file())

    def test_partial_retry_and_idempotence(self):
        for name in list(owner.COHORT)[:2]:
            os.chown(str(self.root / name), owner.UID, owner.GID)
        self.assertEqual(self.run_recovery()['changed'], 6)
        self.assertEqual(self.run_recovery()['changed'], 0)

    def test_check_does_not_create_audit_or_change_ownership(self):
        result = self.run_recovery(False)
        self.assertEqual(result['pending'], 8)
        self.assertIsNone(result['audit'])
        self.assertFalse(self.audit_parent.exists())
        self.assertTrue(all((self.root / name).stat().st_uid == 0 for name in owner.COHORT))

    def test_unlisted_root_publication_blocks_every_change(self):
        os.chown(str(self.extra), 0, 0)
        with mock.patch.object(os, 'fchown') as changed:
            with self.assertRaisesRegex(owner.Stop, 'UNLISTED_OWNER_MISMATCH'):
                self.run_recovery()
            changed.assert_not_called()
        self.assertFalse(self.audit_parent.exists())

    def test_ancestor_or_checkpoint_issues_are_rejected(self):
        for key in ('publication_ancestor_issues', 'next_checkpoint_metadata_issues'):
            report = copy.deepcopy(self.receipt)
            report[key] = [{'path': 'unreviewed'}]
            with self.assertRaisesRegex(owner.Stop, 'UNREVIEWED_PUBLICATION_METADATA'):
                owner.check_audit(report, 'BEFORE')

    def test_wrong_raw_pin_blocks_before_fchown(self):
        (self.root / next(iter(owner.COHORT))).write_bytes(b'<?php /* changed after diagnosis */\n')
        with mock.patch.object(os, 'fchown') as changed:
            with self.assertRaisesRegex(owner.Stop, 'PUBLICATION_RAW_PIN_CHANGED'):
                self.run_recovery()
            changed.assert_not_called()

    def test_wrong_root_mode_blocks_before_fchown(self):
        (self.root / next(iter(owner.COHORT))).chmod(0o775)
        with mock.patch.object(os, 'fchown') as changed:
            with self.assertRaises(owner.Stop):
                self.run_recovery()
            changed.assert_not_called()

    def test_hardlink_and_symlink_are_rejected(self):
        path = self.root / next(iter(owner.COHORT))
        os.link(str(path), str(self.base / 'outside-link'))
        with mock.patch.object(os, 'fchown') as changed:
            with self.assertRaises(owner.Stop):
                self.run_recovery()
            changed.assert_not_called()
        (self.base / 'outside-link').unlink()
        data = path.read_bytes()
        path.unlink()
        outside = self.base / 'outside'
        outside.write_bytes(data)
        path.symlink_to(outside)
        with mock.patch.object(os, 'fchown') as changed:
            with self.assertRaises(Exception):
                self.run_recovery()
            changed.assert_not_called()

    def test_replaced_path_between_preflight_and_write_is_rejected(self):
        name = next(iter(owner.COHORT))
        path = self.root / name
        def replace(call):
            if call == 2:
                data = path.read_bytes()
                mode = stat.S_IMODE(path.stat().st_mode)
                path.unlink()
                path.write_bytes(data)
                path.chmod(mode)
        self.after_read = replace
        with mock.patch.object(os, 'fchown') as changed:
            with self.assertRaises(owner.Stop):
                self.run_recovery()
            changed.assert_not_called()

    def test_os_failure_rolls_back_only_metadata(self):
        original = self.target_invariants()
        real = os.fchown
        count = [0]
        def fail_third(fd, uid, gid):
            if uid == owner.UID:
                count[0] += 1
                if count[0] == 3:
                    raise OSError('PRIVATE_FAILURE_DETAILS')
            return real(fd, uid, gid)
        with mock.patch.object(os, 'fchown', fail_third):
            with self.assertRaisesRegex(owner.Stop, 'RECOVERY_STOPPED_REVIEW_PRIVATE_AUDIT'):
                self.run_recovery()
        self.assertEqual(self.target_invariants(), original)
        self.assertTrue(all((self.root / name).stat().st_uid == 0 for name in owner.COHORT))
        audit = next(self.audit_parent.iterdir())
        self.assertEqual(json.loads((audit / 'stopped.json').read_text())['skipped_changed_or_unavailable'], [])

    def test_rollback_does_not_restore_changed_content(self):
        real = os.fchown
        first = sorted(owner.COHORT)[0]
        count = [0]
        changed_bytes = b'<?php /* external concurrent write must remain */\n'
        def mutate_second(fd, uid, gid):
            if uid == owner.UID:
                count[0] += 1
                if count[0] == 2:
                    (self.root / first).write_bytes(changed_bytes)
                    raise OSError('SECRET')
            return real(fd, uid, gid)
        with mock.patch.object(os, 'fchown', mutate_second):
            with self.assertRaisesRegex(owner.Stop, 'RECOVERY_STOPPED_REVIEW_PRIVATE_AUDIT'):
                self.run_recovery()
        self.assertEqual((self.root / first).read_bytes(), changed_bytes)
        stopped = json.loads((next(self.audit_parent.iterdir()) / 'stopped.json').read_text())
        self.assertIn(first, stopped['skipped_changed_or_unavailable'])

    def test_after_exact_owner_state_is_noop_but_root_owner_rejected(self):
        self.state = 'AFTER'
        for name in owner.COHORT:
            path = self.root / name
            path.write_bytes(local_git(['cat-file', 'blob', self.context['by_path'][name]['after_blob']]))
            os.chown(str(path), owner.UID, owner.GID)
            path.chmod(0o644)
        with mock.patch.object(os, 'fchown') as changed:
            self.assertEqual(self.run_recovery()['changed'], 0)
            changed.assert_not_called()
        os.chown(str(self.root / next(iter(owner.COHORT))), 0, 0)
        with mock.patch.object(os, 'fchown') as changed:
            with self.assertRaises(owner.Stop):
                self.run_recovery()
            changed.assert_not_called()

    def test_full_inspector_rejects_unknown_before_permission_audit(self):
        context = dict(self.context)
        classifier = mock.Mock(return_value='UNKNOWN')
        audit = mock.Mock()
        context['complete'] = dict(context['complete'], classifier=classifier)
        context['permission'] = dict(context['permission'], audit=audit)
        with self.assertRaisesRegex(owner.Stop, 'UNREVIEWED_SERVER_SOURCE'):
            owner.inspect(self.root, context, lambda: 'e' * 40)
        audit.assert_not_called()
        self.assertEqual(classifier.call_args.args[:2], (self.root, context['manifest']))

    def test_successful_inspector_uses_real_pinned_audit_and_metadata_readers(self):
        context = dict(self.context)
        source, scanner = context['source'], context['scanner']
        fixture = scanner['snapshot'](self.root, source, [], [], lambda: 'e' * 40)
        protected = [{'path': 'app/unlisted.php', 'blob': fixture['files']['app/unlisted.php']['blob']}]
        for index in range(27):
            name = 'public/js/dashboard-owner-fixture-%02d.js' % index
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            data = ('/* GUI fixture %d */\n' % index).encode()
            path.write_bytes(data)
            protected.append({'path': name, 'blob': owner.blob(data)})
        publications = [dict(context['by_path'][name]) for name in owner.COHORT]
        publications += [{'path': 'app/new-padding-%03d.php' % index, 'before_blob': None, 'after_blob': 'b' * 40} for index in range(258)]
        context['manifest'] = {'sources': publications, 'protected': protected, 'expected_head': 'e' * 40,
            'before_source': dict(source['digest'](fixture['files']), framework='8.83.29'),
            'after_source': self.context['manifest']['after_source']}
        classifier = mock.Mock(return_value='BEFORE')
        context['complete'] = dict(context['complete'], classifier=classifier)
        original = owner.inspect(self.root, context, lambda: 'e' * 40)
        self.assertEqual(original['state'], 'BEFORE')
        self.assertEqual(len(original['snapshot']['files']), 9)
        self.assertEqual(len(original['snapshot']['additional_dashboard_gui']), 27)
        result = owner.recover(self.root, context, lambda: 'e' * 40, True, owner.State(), audit_parent=self.audit_parent)
        self.assertEqual(result['changed'], 8)
        self.assertTrue(all((self.root / name).stat().st_uid == owner.UID for name in owner.COHORT))
        self.assertGreaterEqual(classifier.call_count, 5)

    def test_pinned_context_and_git_privacy(self):
        self.assertEqual(self.context['complete']['APP_RELEASE'], owner.APP)
        self.assertEqual(self.context['permission']['RELEASE'], owner.APP)
        data = b'pinned fixture'
        pin = owner.blob(data)
        rows = iter([b'100644 blob ' + pin.encode() + b'\tdeployment/test.py\0', str(len(data)).encode() + b'\n', data])
        self.assertEqual(owner.source_file(owner.APP, 'deployment/test.py', pin, lambda *_: next(rows)), data)
        out, err = io.StringIO(), io.StringIO()
        def fail(_args, state):
            state.phase = 'HELD_PUBLICATION_PLAN'
            raise PermissionError('SECRET_TOKEN /root/private')
        with mock.patch.object(owner, 'execute', fail):
            self.assertEqual(owner.main(['--release', 'a' * 40, '--repair'], out, err), 1)
        self.assertEqual(out.getvalue(), '')
        self.assertIn('PHASE=HELD_PUBLICATION_PLAN', err.getvalue())
        self.assertIn('CLASS=PermissionError', err.getvalue())
        self.assertNotIn('SECRET', err.getvalue())
        self.assertNotIn('/root', err.getvalue())

    def test_success_receipt_and_invalid_arguments(self):
        out, err = io.StringIO(), io.StringIO()
        with mock.patch.object(owner, 'execute', return_value={'changed': 8, 'pending': 0, 'audit': '/var/lib/private/repair-owned', 'state': 'BEFORE'}):
            self.assertEqual(owner.main(['--release', 'a' * 40, '--repair'], out, err), 0)
        self.assertEqual(out.getvalue().splitlines(), ['GO_ERP_PUBLICATION_OWNERSHIP_RECOVERED files=8', 'AUDIT_DIRECTORY=/var/lib/private/repair-owned', 'APPLICATION_CONTENTS_CHANGED=false'])
        out, err = io.StringIO(), io.StringIO()
        self.assertEqual(owner.main(['--release', 'SECRET'], out, err), 1)
        self.assertNotIn('SECRET', err.getvalue())


if __name__ == '__main__':
    unittest.main()
