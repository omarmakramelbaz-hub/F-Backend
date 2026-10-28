import base64
from contextlib import redirect_stdout
import hashlib
import importlib.util
import io
import json
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch


ROOT = Path(__file__).resolve().parents[1]


def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'deployment' / filename)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


entry = load('readonly_entry_tests', 'diagnose_go_erp_desktop_performance_readonly_root.py')
payloads = load('readonly_payload_tests', 'go_erp_desktop_performance_payloads.py')
current = load('readonly_current_tests', 'go_erp_desktop_performance_current.py')


def result():
    value = {name: True for name in entry.PUBLIC_FIELDS}
    value.update(format=1, kind='go-erp-desktop-performance-read-only-preflight',
                 current_candidate=current.CANDIDATE, current_version=current.VERSION,
                 current_core=current.CORE, current_seed=current.SEED, new_seed=entry.SEED,
                 derived_native_pins={name: {'blob': 'a' * 40, 'bytes': 12, 'sha256': 'b' * 64}
                                      for name in payloads.CURRENT_PINS})
    return value


def bundle():
    raw = b'PUBLIC_HELPER = True\n'
    pin = hashlib.sha256(raw).hexdigest()
    return {name: (pin, base64.b64encode(raw).decode('ascii')) for name in ('payloads', 'current')}


class ReadOnlyEntry(unittest.TestCase):
    def invoke(self, *, host='srv1836524', uid=0, argv=None, value=None, error=None):
        fake_payloads = SimpleNamespace(CURRENT_NATIVE_HASHES=dict(payloads.CURRENT_NATIVE_HASHES),
                                        CURRENT_PINS=payloads.CURRENT_PINS)
        preflight = Mock(return_value=result() if value is None else value, side_effect=error)
        fake_current = SimpleNamespace(CANDIDATE=current.CANDIDATE, VERSION=current.VERSION,
                                       CORE=current.CORE, SEED=current.SEED, preflight=preflight)
        original = object()
        output = io.StringIO()
        with patch.object(entry.os, 'geteuid', return_value=uid), \
                patch.object(entry.socket, 'gethostname', return_value=host), \
                patch.object(entry.sys, 'argv', ['readonly.py'] if argv is None else argv), \
                patch.object(entry, 'helpers', return_value=(fake_payloads, fake_current)) as helpers, \
                patch.object(entry, 'original_operator', return_value=original) as operator, \
                redirect_stdout(output):
            status = entry.main()
        lines = output.getvalue().splitlines()
        self.assertEqual(len(lines), 1, 'Only one bounded public JSON record may be printed')
        return status, json.loads(lines[0]), preflight, helpers, operator, original, fake_payloads

    def test_nonroot_wrong_host_and_arguments_stop_before_package_or_helpers(self):
        for kwargs in ({'uid': 1000}, {'host': 'different-host'}, {'argv': ['readonly.py', '--apply']}):
            with self.subTest(kwargs=kwargs):
                status, value, preflight, helpers, operator, *_ = self.invoke(**kwargs)
                self.assertEqual(status, 1)
                self.assertEqual(value['phase'], 'host')
                helpers.assert_not_called()
                operator.assert_not_called()
                preflight.assert_not_called()

    def test_host_fqdn_and_exact_preflight_bindings(self):
        status, value, preflight, helpers, operator, original, fake_payloads = self.invoke(host='srv1836524.example')
        self.assertEqual(status, 0)
        self.assertEqual(value, result())
        helpers.assert_called_once_with()
        operator.assert_called_once_with()
        expected = dict(payloads.CURRENT_NATIVE_HASHES)
        expected.update(entry.NATIVE_CHANGES)
        preflight.assert_called_once_with(original, fake_payloads, seed=entry.SEED,
                                         packaged_native_hashes=expected)
        self.assertEqual(fake_payloads.CURRENT_NATIVE_HASHES, payloads.CURRENT_NATIVE_HASHES)
        self.assertEqual(set(entry.NATIVE_CHANGES), {'main.cjs', 'dashboard.cjs', 'workspace-delta.cjs'})

    def test_exception_diagnostics_never_print_private_error_contents(self):
        secret = 'mysql://root:PRIVATE_PASSWORD@localhost/db PRIVATE_CONFIG_TOKEN /private/account/owner.json'
        status, value, *_ = self.invoke(error=RuntimeError(secret))
        self.assertEqual(status, 1)
        self.assertEqual(value['code'], 'GO_ERP_DESKTOP_PERFORMANCE_READ_ONLY_FAILURE')
        self.assertEqual(value['phase'], 'preflight')
        self.assertEqual(value['error_type'], 'RuntimeError')
        self.assertNotIn('PRIVATE_', json.dumps(value))
        self.assertNotIn('/private/', json.dumps(value))
        self.assertLessEqual(len(value['frames']), 8)
        self.assertTrue(all(len(frame) == 3 and '/' not in frame[0] for frame in value['frames']))

    def test_only_fixed_public_error_codes_are_preserved(self):
        code = 'GO_ERP_DESKTOP_PERFORMANCE_CURRENT_BUSY'
        status, value, *_ = self.invoke(error=RuntimeError(code))
        self.assertEqual((status, value['code']), (1, code))
        for message in (code + ' PRIVATE_CONFIG_TOKEN', code + '\nPRIVATE_CONFIG_TOKEN'):
            with self.subTest(message=message):
                _, value, *_ = self.invoke(error=RuntimeError(message))
                self.assertEqual(value['code'], 'GO_ERP_DESKTOP_PERFORMANCE_READ_ONLY_FAILURE')

    def test_extra_private_result_field_is_rejected_without_serializing_it(self):
        value = result()
        value['credentials'] = 'PRIVATE_CONFIG_TOKEN'
        status, diagnostic, *_ = self.invoke(value=value)
        self.assertEqual(status, 1)
        self.assertEqual(diagnostic['code'], 'GO_ERP_DESKTOP_PERFORMANCE_READ_ONLY_RESULT_FIELDS_CHANGED')
        self.assertNotIn('PRIVATE_CONFIG_TOKEN', json.dumps(diagnostic))

    def test_public_result_requires_exact_identity_true_flags_and_pin_roster(self):
        mutations = [lambda value: value.update(current_candidate='f' * 40),
                     lambda value: value.update(new_seed=current.SEED),
                     lambda value: value.update(read_only=False),
                     lambda value: value.update(original_locks_held=1),
                     lambda value: value['derived_native_pins'].pop(next(iter(payloads.CURRENT_PINS))),
                     lambda value: value['derived_native_pins'].update({'private.env': {'blob': 'a' * 40, 'bytes': 1, 'sha256': 'b' * 64}})]
        for mutate in mutations:
            value = result()
            mutate(value)
            with self.subTest(value=value), self.assertRaises(entry.Stop):
                entry.public_result(value, payloads, current)

    def test_pin_rows_expose_only_bounded_public_code_identifiers(self):
        for changed in ({'bytes': True}, {'bytes': 0}, {'bytes': 2 * 1024 ** 2 + 1},
                        {'blob': 'PRIVATE_PATH'}, {'sha256': 'G' * 64}, {'source': 'PRIVATE_CODE'}):
            value = result()
            value['derived_native_pins'][next(iter(payloads.CURRENT_PINS))].update(changed)
            with self.subTest(changed=changed), self.assertRaises(entry.Stop):
                entry.public_result(value, payloads, current)
        self.assertEqual(set(entry.public_result(result(), payloads, current)), entry.PUBLIC_FIELDS)

    def test_all_bundle_members_are_validated_before_any_helper_executes(self):
        malformed = [None, {}, {**bundle(), 'extra': ('a' * 64, '')}]
        for row in (None, (), ('a' * 64,), ('a' * 64, '', 'extra'),
                    ('INVALID', ''), ('a' * 64, '%%not-base64%%'),
                    ('a' * 64, 'A' * (256 * 1024 + 1)),
                    ('a' * 64, base64.b64encode(b'TAMPERED = True').decode('ascii'))):
            value = bundle()
            value['current'] = row
            malformed.append(value)
        for value in malformed:
            with self.subTest(value_type=type(value).__name__), \
                    patch.object(entry, 'BUNDLED_MODULES', value), \
                    patch.object(entry, 'module') as execute, self.assertRaises(entry.Stop):
                try:
                    entry.helpers()
                finally:
                    execute.assert_not_called()

    def test_module_rejects_changed_bytes_before_compiling_or_exec(self):
        with patch.object(entry, 'exec', create=True) as execute:
            with self.assertRaisesRegex(entry.Stop, 'MODULE_HASH_CHANGED'):
                entry.module(b'raise RuntimeError("PRIVATE")', 'bad', '0' * 64)
            execute.assert_not_called()

    def test_embedded_helpers_are_exact_adjacent_public_source_bytes(self):
        self.assertEqual(set(entry.BUNDLED_MODULES), {'payloads', 'current'})
        for name in ('payloads', 'current'):
            expected, encoded = entry.BUNDLED_MODULES[name]
            raw = (ROOT / 'deployment' / ('go_erp_desktop_performance_' + name + '.py')).read_bytes()
            self.assertEqual(base64.b64decode(encoded, validate=True), raw)
            self.assertEqual(expected, hashlib.sha256(raw).hexdigest())


if __name__ == '__main__':
    unittest.main()
