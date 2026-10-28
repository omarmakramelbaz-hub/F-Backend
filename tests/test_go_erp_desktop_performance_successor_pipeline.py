"""Execute the frozen migration and natural consumed-61 -> next-62 pipeline.

All data here is isolated public fixture data, never release authorization.
"""
import copy
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
BASE = Path(__file__).resolve().parents[1]
ORIGINAL = BASE / 'tests/test_go_erp_desktop_performance_candidate_pipeline.py'
if not ORIGINAL.exists():
    ORIGINAL = ROOT / 'perf_operator/tests/test_go_erp_desktop_performance_candidate_pipeline.py'


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


pipeline = load('successor_original_pipeline', ORIGINAL)
WARM = BASE / 'tests/fixtures/go_erp_desktop_performance/warm_start_root.py'
if not WARM.exists():
    WARM = ROOT / 'perf_operator/tests/fixtures/go_erp_desktop_performance/warm_start_root.py'
warm = load('successor_original_migration', WARM)


class ActualSuccessorPipeline(unittest.TestCase):
    write = pipeline.Pipeline.write
    commit = pipeline.Pipeline.commit
    fixture = pipeline.Pipeline.fixture

    def failed_state(self, fixture):
        return {'format': 1, 'base_revision': 'd' * 40, 'counter': 61,
            'version_floor': '0.4.4-preview.60', 'sequence_floor': 123,
            'last_remote_sha': '7' * 40,
            'observed': pipeline.source._metadata('d' * 40, fixture.snapshot['metadata']['inventory']),
            'desired': {'commit': '7' * 40, 'version': '0.4.4-preview.61',
                'status': 'retry-build', 'pushed': True, 'failures': 1, 'retry_at': 900},
            'published': {'commit': '6' * 40, 'release': {'version': '0.4.4-preview.60'}},
            'published_history': [{'version': '0.4.4-preview.60'}],
            'dependency_guard': {'fixture': 'unchanged live dependency digest'}}

    def test_original_migration_keeps_failed_candidate_and_all_high_water_history(self):
        fixture = self.fixture(60)
        state = self.failed_state(fixture)
        original = copy.deepcopy(state)
        with patch.multiple(warm, OLD_SEED='d' * 40, SEED=fixture.seed):
            migrated = warm.migrate_state(state, pipeline.source, pipeline.source)
        self.assertEqual(state, original)
        self.assertEqual(migrated['base_revision'], fixture.seed)
        self.assertEqual(migrated['observed']['seedRevision'], fixture.seed)
        self.assertEqual({k: v for k, v in migrated.items() if k not in ('base_revision', 'observed')},
                         {k: v for k, v in original.items() if k not in ('base_revision', 'observed')})
        self.assertEqual(migrated['counter'], 61)
        self.assertEqual(migrated['desired']['status'], 'retry-build')

    def test_frozen_allocator_naturally_builds62_without_reusing_or_rewinding61(self):
        fixture = self.fixture(60)
        state = self.failed_state(fixture)
        with patch.multiple(warm, OLD_SEED='d' * 40, SEED=fixture.seed):
            fixture.worker.state = warm.migrate_state(state, pipeline.source, pipeline.source)
        previous = copy.deepcopy(fixture.worker.state)
        sequence = fixture.worker.next_counter()
        self.assertEqual(sequence, 62)
        durable = json.loads(fixture.worker.state_file.read_bytes())
        self.assertEqual(durable['counter'], 62)
        self.assertEqual(durable['desired'], previous['desired'])
        self.assertEqual(durable['published_history'], previous['published_history'])
        self.assertEqual(durable['sequence_floor'], previous['sequence_floor'])
        result = pipeline.source.prepare_candidate(fixture.checkout, fixture.snapshot, sequence)
        self.assertEqual(result['version'], '0.4.4-preview.62')
        self.assertEqual(pipeline.policy.parents(fixture.checkout, result['commit']), [fixture.seed])
        candidate = json.loads((fixture.checkout / pipeline.source.CANDIDATE_PATH).read_bytes())
        pipeline.policy.validate_candidate_delta(fixture.checkout, result['commit'], fixture.seed, candidate)
        self.assertEqual(candidate['versionSequence'], 62)
        self.assertEqual(candidate['baseRevision'], fixture.seed)


if __name__ == '__main__':
    unittest.main()
