import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('performance_payloads', ROOT / 'deployment/go_erp_desktop_performance_payloads.py')
payloads = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(payloads)
SEED = 'f182ca39e0a093f068913db858624d251db33abe'


def target():
    result = dict(payloads.CURRENT_NATIVE_HASHES)
    for number, name in enumerate(sorted(payloads.CHANGED_NATIVE), 1):
        result[name] = str(number) * 64
    return result


def worker():
    return ("PREVIEW31_SEED = '" + payloads.CURRENT_SEED + "'\n"
            "ORIGINAL_PUBLISHER = 'byte-identical'\n"
            "def verify_installed_startup_source(value):\n"
            "    need(value.get('installedAsarCode') is True\n"
            "         and value.get('originalWindowsInstaller') is True\n"
            "         and value.get('originalRuntime') is True\n"
            "         and value.get('sourceRevision') == 'accepted-exact-candidate'\n"
            "         and value.get('sourceHashes') == {'helper': 'unchanged-proof'}\n"
            "         and value.get('packagedNativeHashes') == " + repr(dict(sorted(payloads.CURRENT_NATIVE_HASHES.items()))) + "\n"
            "         and value.get('actualHttpsPublication') is False, 'ORIGINAL_PROOF_FAILED')\n"
            "def original_publisher():\n"
            "    return ORIGINAL_PUBLISHER\n").encode()


class Rebinding(unittest.TestCase):
    def transformed(self):
        return payloads.rebind_worker(worker(), payloads.CURRENT_SEED, SEED,
                                      payloads.CURRENT_NATIVE_HASHES, target())

    def test_exact_current_input_pins_are_public_frozen_bindings(self):
        pin = payloads.CURRENT_PINS['go_erp_live_updates.py']
        self.assertEqual(pin, {'blob': '5818feb84b52f8706f6a0429aac738e8f741773b', 'size': 79669,
                               'sha256': '9a53103762c01884a60bcf81d68ec1efa0763fc38ff4ce6af482cc0c122cc8e1'})
        self.assertEqual(len(payloads.CURRENT_PINS), 7)
        self.assertEqual(payloads.CURRENT_SEED, 'dc06af6f8e4ef9ea03a3fb9c728d26acb2f8193a')

    def test_rebind_changes_only_reviewed_seed_and_three_hash_values(self):
        old, new = worker(), self.transformed()
        restored = new.replace(SEED.encode(), payloads.CURRENT_SEED.encode())
        for name in payloads.CHANGED_NATIVE:
            restored = restored.replace(target()[name].encode(), payloads.CURRENT_NATIVE_HASHES[name].encode())
        self.assertEqual(restored, old)
        self.assertNotEqual(old, new)

    def test_all_original_proof_predicates_remain_effective(self):
        def need(condition, code):
            if not condition:
                raise ValueError(code)
        namespace = {'need': need}
        exec(self.transformed(), namespace)
        value = {'installedAsarCode': True, 'originalWindowsInstaller': True, 'originalRuntime': True,
                 'sourceRevision': 'accepted-exact-candidate', 'sourceHashes': {'helper': 'unchanged-proof'},
                 'packagedNativeHashes': target(), 'actualHttpsPublication': False}
        namespace['verify_installed_startup_source'](value)
        for name, bad in [('installedAsarCode', False), ('originalWindowsInstaller', False),
                          ('originalRuntime', False), ('sourceRevision', 'other'),
                          ('sourceHashes', {}), ('packagedNativeHashes', payloads.CURRENT_NATIVE_HASHES),
                          ('actualHttpsPublication', True)]:
            with self.subTest(name=name), self.assertRaisesRegex(ValueError, 'ORIGINAL_PROOF_FAILED'):
                namespace['verify_installed_startup_source']({**value, name: bad})
        self.assertEqual(namespace['original_publisher'](), 'byte-identical')

    def test_wrong_or_extra_native_members_fail_closed(self):
        for changed in [dict(target(), **{'unreviewed.cjs': 'a' * 64}),
                        {k: v for k, v in target().items() if k != 'workspace-native.cjs'},
                        dict(target(), **{'workspace-native.cjs': 'a' * 64})]:
            with self.subTest(changed=changed.keys()), self.assertRaises(payloads.Stop):
                payloads.rebind_worker(worker(), payloads.CURRENT_SEED, SEED, payloads.CURRENT_NATIVE_HASHES, changed)

    def test_missing_reviewed_fix_is_rejected(self):
        changed = target()
        changed['dashboard.cjs'] = payloads.CURRENT_NATIVE_HASHES['dashboard.cjs']
        with self.assertRaisesRegex(payloads.Stop, 'NATIVE_CHANGE_SCOPE_CHANGED'):
            payloads.rebind_worker(worker(), payloads.CURRENT_SEED, SEED, payloads.CURRENT_NATIVE_HASHES, changed)

    def test_code_and_hash_injection_are_rejected(self):
        for value in ["'; execute() #", 'g' * 40, '0' * 40, payloads.CURRENT_SEED]:
            with self.subTest(value=value), self.assertRaises(payloads.Stop):
                payloads.rebind_worker(worker(), payloads.CURRENT_SEED, value, payloads.CURRENT_NATIVE_HASHES, target())
        changed = target()
        changed['dashboard.cjs'] = "'; execute() #"
        with self.assertRaisesRegex(payloads.Stop, 'EXACT_ASAR_NATIVE_MAP_REQUIRED'):
            payloads.rebind_worker(worker(), payloads.CURRENT_SEED, SEED, payloads.CURRENT_NATIVE_HASHES, changed)

    def test_source_rebind_does_not_change_policy_or_collector(self):
        source = ("PREVIEW31_SEED = '" + payloads.CURRENT_SEED + "'\n"
                  "POLICY = 'current-shared-workspace'\n"
                  "def capture():\n    return 'original-collector'\n").encode()
        changed = payloads.rebind_seed(source, payloads.CURRENT_SEED, SEED)
        self.assertEqual(changed.replace(SEED.encode(), payloads.CURRENT_SEED.encode()), source)

    def test_duplicate_seed_or_native_predicate_is_rejected(self):
        for raw in [worker() + ("\nEXTRA = '" + payloads.CURRENT_SEED + "'\n").encode(),
                    worker() + worker(), worker().replace(b"value.get('packagedNativeHashes') ==", b"value.get('packagedNativeHashes') !=")]:
            with self.subTest(raw_size=len(raw)), self.assertRaises(payloads.Stop):
                payloads.rebind_worker(raw, payloads.CURRENT_SEED, SEED, payloads.CURRENT_NATIVE_HASHES, target())

    def test_foreign_hash_map_is_not_treated_as_current(self):
        raw = worker().replace(payloads.CURRENT_NATIVE_HASHES['main.cjs'].encode(), b'f' * 64)
        with self.assertRaisesRegex(payloads.Stop, 'STARTUP_NATIVE_PREDICATE_CHANGED'):
            payloads.rebind_worker(raw, payloads.CURRENT_SEED, SEED, payloads.CURRENT_NATIVE_HASHES, target())

    def test_strict_current_wrapper_rejects_unpinned_payloads(self):
        fake = {name: b'not-the-pinned-native-file' for name in payloads.CURRENT_PINS}
        with self.assertRaisesRegex(payloads.Stop, 'CURRENT_PAYLOAD_BYTES_CHANGED'):
            payloads.native(fake, seed=SEED, packaged_native_hashes=target())
        with self.assertRaisesRegex(payloads.Stop, 'CURRENT_PAYLOAD_ROSTER_CHANGED'):
            payloads.native({}, seed=SEED, packaged_native_hashes=target())

    def test_complete_wrapper_preserves_other_five_files_and_rejects_tamper(self):
        # A synthetic cohort exercises orchestration; it does not certify the
        # production worker. Its input pin map is confined to this test process.
        fixture = {name: (b'name: untouched-workflow\n' if name.endswith('.yml') else b'ORIGINAL = True\n')
                   for name in payloads.CURRENT_PINS}
        fixture['go_erp_live_updates.py'] = worker()
        fixture['go_erp_live_source.py'] = ("PREVIEW31_SEED = '" + payloads.CURRENT_SEED + "'\n").encode()
        fixture_pins = {name: {'blob': payloads.blob(raw), 'size': len(raw), 'sha256': payloads.sha(raw)}
                        for name, raw in fixture.items()}
        with patch.object(payloads, 'CURRENT_PINS', fixture_pins):
            result = payloads.native(fixture, seed=SEED, packaged_native_hashes=target())
            for name in set(fixture) - {'go_erp_live_updates.py', 'go_erp_live_source.py'}:
                self.assertEqual(result[name], fixture[name])
            self.assertEqual(fixture['go_erp_live_updates.py'], worker())
            for name in fixture:
                corrupted = {**fixture, name: fixture[name] + b'\n'}
                with self.subTest(name=name), self.assertRaisesRegex(payloads.Stop, 'CURRENT_PAYLOAD_BYTES_CHANGED'):
                    payloads.native(corrupted, seed=SEED, packaged_native_hashes=target())

    def test_original_derive_then_transform_callback_reproves_current_bytes(self):
        fixture = {name: (b'name: original\n' if name.endswith('.yml') else b'ORIGINAL = True\n')
                   for name in payloads.CURRENT_PINS}
        fixture['go_erp_live_updates.py'] = worker()
        fixture['go_erp_live_source.py'] = ("PREVIEW31_SEED = '" + payloads.CURRENT_SEED + "'\n").encode()
        fixture_pins = {name: {'blob': payloads.blob(raw), 'size': len(raw), 'sha256': payloads.sha(raw)}
                        for name, raw in fixture.items()}
        rebound = dict(fixture)
        for name in ('go_erp_live_updates.py', 'go_erp_live_source.py'):
            rebound[name] = payloads.rebind_seed(rebound[name], payloads.CURRENT_SEED, SEED)
        with patch.object(payloads, 'CURRENT_PINS', fixture_pins):
            direct = payloads.native(fixture, seed=SEED, packaged_native_hashes=target())
            callback = payloads.after_original_seed_rebind(rebound, seed=SEED, packaged_native_hashes=target())
            self.assertEqual(callback, direct)
            rebound['go_erp_live_updates.py'] += b'\n'
            with self.assertRaisesRegex(payloads.Stop, 'CURRENT_PAYLOAD_BYTES_CHANGED'):
                payloads.after_original_seed_rebind(rebound, seed=SEED, packaged_native_hashes=target())


if __name__ == '__main__':
    unittest.main()
