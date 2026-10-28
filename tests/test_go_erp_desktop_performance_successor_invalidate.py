"""The original failed-candidate path legitimately retires the public feed."""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest

BASE = Path(__file__).resolve().parents[1]
FIXTURE = BASE / 'tests/fixtures/go_erp_desktop_performance/worker_publisher.py'
if not FIXTURE.exists():
    FIXTURE = BASE.parent / 'perf_operator/tests/fixtures/go_erp_desktop_performance/worker_publisher.py'
spec = importlib.util.spec_from_file_location('actual_successor_invalidate_worker', FIXTURE)
worker = importlib.util.module_from_spec(spec); spec.loader.exec_module(worker)


class ActualInvalidate(unittest.TestCase):
    def test_original_invalidate_removes_feed_and_preserves_consumed61_and_published60_history(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            live = root / 'live'; checkout = root / 'checkout'; work = root / 'work'
            feed = live / 'storage/app/desktop-updates/current.json'
            feed.parent.mkdir(parents=True, mode=0o700)
            (checkout / 'desktop-pos').mkdir(parents=True, mode=0o700)
            (checkout / 'desktop-pos/package.json').write_text('{"version":"0.4.4-preview.60"}')
            (work / 'state').mkdir(parents=True, mode=0o700)
            release = {'version': '0.4.4-preview.60', 'sequence': 123,
                       'source_revision': 'd1976ce02c553939032241e7e724d26a9bbe7190'}
            feed.write_bytes(worker.canonical(release)); feed.chmod(0o600)
            current = object.__new__(worker.Worker)
            current.live, current.checkout = live, checkout
            current.state_file = work / 'state/current.json'
            current.state = {'counter': 61, 'version_floor': '0.4.4-preview.60', 'sequence_floor': 123,
                             'published': {'commit': release['source_revision'], 'release': release},
                             'published_history': [release],
                             'desired': {'commit': '766f9d2d101fd639ffea8905e5b309f03eab8759',
                                         'version': '0.4.4-preview.61', 'status': 'retry-build'}}
            current.publisher = SimpleNamespace(
                read_json=lambda path, **_kwargs: json.loads(path.read_bytes()),
                validate_release=lambda value: value,
                compare_versions=lambda _a, _b: 0)
            before = copy.deepcopy(current.state)
            current.invalidate()
            self.assertFalse(feed.exists())
            self.assertEqual(current.state, before)
            self.assertEqual(json.loads(current.state_file.read_bytes()), before)
            current.invalidate()  # An already missing feed remains a valid retry baseline.
            self.assertEqual(current.state, before)


if __name__ == '__main__':
    unittest.main()
