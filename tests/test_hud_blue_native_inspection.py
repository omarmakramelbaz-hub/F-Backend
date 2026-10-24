"""Read-only native metadata inspection never exposes configuration values."""
import contextlib
import copy
import fcntl
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('hud_blue_native_inspection',
    ROOT / 'deployment/inspect_hud_blue_native_root.py')
subject = importlib.util.module_from_spec(spec)
spec.loader.exec_module(subject)
SECRET = 'TOP_SECRET_token_and_private_config_DO_NOT_PRINT'


class InspectionTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.work = self.root / 'work'
        self.work.mkdir(mode=0o700)
        (self.work / 'state').mkdir(mode=0o700)
        self.lock = self.work / 'watcher.lock'
        self.lock.write_bytes(b'')
        self.lock.chmod(0o600)
        self.config = self.root / 'config.json'
        self.feed = self.root / 'feed.json'
        self.state = self.work / 'state/current.json'
        self.held = self.work / 'program-rollout-hold.json'
        self.seed, self.commit = 'a' * 40, 'b' * 40
        self.source = {'format': 1, 'files': 1355, 'framework': '8.83.29', 'sha256': 'c' * 64}
        self.release = {'version': '0.4.4-preview.40', 'source_revision': self.commit,
            'source': self.source, 'sequence': 40046091951, 'private_ignored': SECRET}
        self.cfg = {'base_revision': self.seed, 'token': SECRET,
            'private_paths': [SECRET], 'private_ignored': {'never': SECRET}}
        self.current = {'format': 1, 'base_revision': self.seed, 'counter': 40,
            'last_remote_sha': self.commit, 'version_floor': '0.4.4-preview.37',
            'sequence_floor': 39967960771, 'published': None,
            'desired': {'commit': self.commit, 'version': '0.4.4-preview.40', 'status': 'waiting-ci',
                'inventorySha256': 'd' * 64, 'pushed': True, 'candidatePath': SECRET},
            'private_ignored': SECRET}
        self.hold = {'format': 1, 'kind': 'go-erp-program-rollout-hold', 'core': 'e' * 40,
            'source': self.source, 'inventory_sha256': 'd' * 64, 'authorization': None}
        for path, value in ((self.config, self.cfg), (self.state, self.current), (self.held, self.hold)):
            self.write(path, value)

    def write(self, path, value, mode=0o600):
        path.write_text(json.dumps(value))
        path.chmod(mode)

    def inspect(self):
        return subject.inspect(self.work, self.config, self.feed)

    def main(self):
        inspect = subject.inspect
        with patch.object(subject.socket, 'gethostname', return_value='srv1836524'), \
                patch.object(subject, 'inspect', side_effect=lambda: inspect(self.work, self.config, self.feed)), \
                contextlib.redirect_stdout(io.StringIO()) as output:
            status = subject.main()
        return status, output.getvalue()

    def test_genuine_pending_state_is_compact_and_all_private_values_are_omitted(self):
        before = {path: (path.read_bytes(), subject.identity(path.stat()))
            for path in (self.config, self.state, self.held, self.lock)}
        status, output = self.main()
        self.assertEqual(status, 0)
        self.assertTrue(output.startswith('HUD_BLUE_NATIVE_STATE '))
        self.assertNotIn(SECRET, output)
        self.assertNotIn('candidatePath', output)
        self.assertNotIn('token', output)
        value = json.loads(output.partition(' ')[2])
        self.assertEqual(value['seed'], self.seed)
        self.assertEqual(value['counter'], 40)
        self.assertEqual(value['desired']['commit'], self.commit)
        self.assertEqual(value['desired']['status'], 'waiting-ci')
        self.assertFalse(value['hold']['authorized'])
        self.assertIsNone(value['feed'])
        self.assertIsNone(value['published'])
        for path, previous in before.items():
            self.assertEqual((path.read_bytes(), subject.identity(path.stat())), previous)

    def test_completed_feed_and_published_state_are_summarized_without_private_fields(self):
        self.current['published'] = {'commit': self.commit, 'inventorySha256': 'd' * 64,
            'release': self.release, 'private_ignored': SECRET}
        self.current['desired']['status'] = 'published'
        self.write(self.state, self.current)
        self.write(self.feed, self.release, 0o644)
        self.held.unlink()
        result = self.inspect()
        self.assertIsNone(result['hold'])
        self.assertEqual(result['published']['release'], result['feed'])
        self.assertNotIn(SECRET, json.dumps(result))

    def test_authorization_exposes_only_boolean_and_bound_candidate(self):
        self.hold['authorization'] = {'candidate': self.commit, 'version': '0.4.4-preview.40',
            'receipt_path': SECRET, 'receipt_sha256': 'f' * 64}
        self.write(self.held, self.hold)
        result = self.inspect()
        self.assertTrue(result['hold']['authorized'])
        self.assertEqual(result['hold']['authorization_candidate'], self.commit)
        self.assertNotIn(SECRET, json.dumps(result))

    def test_missing_native_work_directory_is_available_false_without_configuration_read(self):
        result = subject.inspect(self.root / 'absent', self.root / 'secret-unreadable', self.feed)
        self.assertEqual(result, {'available': False, 'seed': None})

    def test_busy_lock_is_nonblocking_and_reports_one_fixed_line(self):
        with self.lock.open('rb') as lock:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            status, output = self.main()
        self.assertEqual(status, 1)
        self.assertEqual(output, 'HUD_BLUE_NATIVE_STATE_UNAVAILABLE\n')

    def test_poisoned_whitelist_values_and_duplicate_or_nonfinite_json_cannot_escape(self):
        poison = []
        for field, value in [('counter', True), ('base_revision', SECRET), ('sequence_floor', -1)]:
            state = copy.deepcopy(self.current)
            state[field] = value
            poison.append(json.dumps(state))
        state = copy.deepcopy(self.current)
        state['desired']['status'] = SECRET
        poison.append(json.dumps(state))
        state = copy.deepcopy(self.current)
        state['desired']['pushed'] = 1
        poison.append(json.dumps(state))
        poison.extend(['{"format":1,"format":2,"private":"' + SECRET + '"}',
            '{"private":NaN}', 'malformed-' + SECRET])
        for raw in poison:
            with self.subTest(raw=raw[:35]):
                self.state.write_text(raw)
                status, output = self.main()
                self.assertEqual(status, 1)
                self.assertEqual(output, 'HUD_BLUE_NATIVE_STATE_UNAVAILABLE\n')

    def test_symlink_hardlink_wrong_mode_and_oversized_inputs_fail_closed(self):
        original = self.config.read_bytes()
        self.config.unlink()
        self.config.symlink_to(self.state)
        self.assertEqual(self.main(), (1, 'HUD_BLUE_NATIVE_STATE_UNAVAILABLE\n'))
        self.config.unlink()
        os.link(self.state, self.config)
        self.assertEqual(self.main(), (1, 'HUD_BLUE_NATIVE_STATE_UNAVAILABLE\n'))
        self.config.unlink()
        self.config.write_bytes(original)
        self.config.chmod(0o644)
        self.assertEqual(self.main(), (1, 'HUD_BLUE_NATIVE_STATE_UNAVAILABLE\n'))
        self.config.chmod(0o600)
        self.config.write_bytes(b' ' * 16385)
        self.assertEqual(self.main(), (1, 'HUD_BLUE_NATIVE_STATE_UNAVAILABLE\n'))

    def test_intervening_control_change_is_detected_before_any_metadata_is_printed(self):
        original = subject.read_regular
        reads = 0
        def read(*args, **kwargs):
            nonlocal reads
            result = original(*args, **kwargs)
            reads += 1
            if reads == 4:
                self.current['counter'] += 1
                self.write(self.state, self.current)
            return result
        with patch.object(subject, 'read_regular', side_effect=read):
            self.assertEqual(self.main(), (1, 'HUD_BLUE_NATIVE_STATE_UNAVAILABLE\n'))


if __name__ == '__main__':
    unittest.main()
