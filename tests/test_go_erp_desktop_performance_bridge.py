"""Exercise the actual frozen Context's closed-over Release conversion guard."""
import copy
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import patch

from test_go_erp_desktop_performance_rollout import NATIVE, TEMPLATE, roll, current


class FrozenReleaseBridge(unittest.TestCase):
    def test_actual_context_keeps_both_exact_predecessor_aliases(self):
        native = SimpleNamespace()
        exec(compile(NATIVE, '<pinned-native-bridge-test>', 'exec'), native.__dict__)
        previous_tick = b'NEW_COHORT = {}\n# exact retained predecessor tick\n'
        profile = {
            'core': current.CORE, 'seed': current.SEED, 'runtime': 'policy-' + 'a' * 64,
            'policy': current.POLICY, 'operator_release': roll.ROOT_RELEASE,
            'tick_path': current.TICK_PATH, 'job_prefix': 'shared-workspace-',
            'manual_pins': {'go_erp_manual_tick_root.py': current.sha(previous_tick),
                           'go_erp_manual_updates_root.py': '2' * 64,
                           'go_erp_published_download_root.py': '3' * 64}}
        native.PROFILES[60] = copy.deepcopy(profile)
        paths = {'go_erp_manual_tick_root.py': current.TICK_PATH,
                 'go_erp_manual_updates_root.py': 'deployment/go_erp_shared_workspace_manual_root.py',
                 'go_erp_published_download_root.py': 'deployment/go_erp_published_download_root.py'}
        manual = {name: ('NEXT_PROGRAM = ' + repr(name) + '\n').encode()
                  for name in native.MANUAL_NAMES}
        previous = {'counter': 60, 'candidate': current.CANDIDATE, 'version': current.VERSION,
                    'inventory_sha256': current.INVENTORY, 'release_sha256': 'a' * 64,
                    'acceptance_receipt_sha256': 'b' * 64}
        pins = {'core': roll.CORE, 'seed': roll.SEED, 'web_release': roll.WEB_RELEASE,
                'policy': current.POLICY, 'inventory_sha256': roll.EXPECTED_INVENTORY,
                'contract_sha256': 'c' * 64, 'context_sha256': current.sha(NATIVE),
                'native_payloads': {name: {'blob': row[0], 'size': row[1], 'sha256': row[2]}
                                    for name, row in roll.DERIVED_PINS.items()},
                'manual_programs': {name: {'path': paths[name], 'sha256': current.sha(raw)}
                                    for name, raw in manual.items()}, 'predecessor': previous}
        configured = native.configure(TEMPLATE, SimpleNamespace(), SimpleNamespace(),
            pins=pins, package_payloads=manual, verify_locks=lambda: True,
            workspace_acceptance=lambda *_args: None)
        frozen_bound_release = configured.Release
        original_release = frozen_bound_release.__mro__[1]
        original_context = configured.Context.__mro__[1]
        observation = {'profile': profile,
                       '_manual_payloads': {'go_erp_manual_tick_root.py': previous_tick}}
        current.bind_configured_release(configured, observation)
        supplied = configured.Release('/private/release.git', roll.ROOT_RELEASE)
        received = []

        def inherited_init(context, release, engine):
            received.append(release)
            context.owner = SimpleNamespace(pw_uid=1001, pw_gid=1003)
            context.utility = SimpleNamespace()
            # These reads occur inside the actual frozen Context constructor,
            # after its closed-over isinstance/possible re-wrap operation.
            self.assertIs(release, supplied)
            self.assertEqual(release.file(current.TICK_PATH, roll.ROOT_RELEASE), previous_tick)
            self.assertEqual(release.file(current.STARTUP_ALIAS, roll.ROOT_RELEASE), previous_tick)

        with patch.object(original_context, '__init__', inherited_init), \
                patch.object(original_release, 'git', return_value=b'ORIGINAL_UNRELATED_BYTES\n') as git, \
                patch.object(native.os, 'geteuid', return_value=0), \
                patch.object(native.socket, 'gethostname', return_value='srv1836524.example.invalid'):
            context = configured.Context(supplied, SimpleNamespace())
            self.assertEqual(supplied.file(current.TICK_PATH), manual['go_erp_manual_tick_root.py'])
            self.assertEqual(supplied.file('unrelated.txt', roll.ROOT_RELEASE), b'ORIGINAL_UNRELATED_BYTES\n')
        self.assertEqual(received, [supplied])
        self.assertIsInstance(supplied, frozen_bound_release)
        self.assertEqual(context.job_path.name, 'shared-workspace-' + roll.CORE + '.json')
        self.assertEqual(context.prior_job_path.name, 'shared-workspace-' + current.CORE + '.json')
        git.assert_called_once_with('show', roll.ROOT_RELEASE + ':unrelated.txt')


if __name__ == '__main__':
    unittest.main()
