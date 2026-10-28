"""Site adapter tests with real temporary probe/WAL files and mocked TLS transport."""
import importlib.util
import contextlib
import io
import json
from pathlib import Path
import re
import sys
import types
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


fixtures = load('site_https_fixtures', 'tests/test_go_erp_shared_workspace_activation_root.py')
adapter = load('site_https_adapter', 'deployment/resume_go_erp_site_https_root.py')
subject = fixtures.subject
PINNED = (ROOT / 'deployment/go_erp_shared_workspace_activation_root.py').read_bytes()


class SiteHTTPSTest(unittest.TestCase):
    def setUp(self):
        self.fixture = fixtures.ActivationTest('runTest')
        self.fixture.setUp()
        self.addCleanup(self.fixture.tearDown)
        self.original_https = subject.Activation._https
        self.original_get = subject.HTTPSClient.get
        self.original_perform = subject.Activation._perform
        self.addCleanup(setattr, subject.Activation, '_https', self.original_https)
        self.addCleanup(setattr, subject.HTTPSClient, 'get', self.original_get)
        self.addCleanup(setattr, subject.Activation, '_perform', self.original_perform)
        self.root = self.fixture.root
        for name, content in (('.htaccess', b'# live cPanel rules\nRewriteEngine On\n'),
                              ('public/.htaccess', b'RewriteRule ^ index.php [L]\n')):
            p = self.root / name
            p.write_bytes(content)
            p.chmod(0o644)
        # Bind the real ROOT documentroot, exactly as the production checkpoint.
        self.fixture.handler['configuration']['documentroot'] = str(self.root)
        self.fixture.pins['binding']['php_handler_configuration_sha256'] = subject.digest(
            self.fixture.handler['configuration'])
        self.fixture.engine = self.fixture.make()
        adapter.install_adapter(types.SimpleNamespace(modules={'activation': subject}),
                                PINNED, '7' * 64, adapter.ADDRESS)

    def action(self):
        return self.fixture.action('https_readiness')

    def folder(self, action):
        return self.fixture.engine._folder(action)

    def fail_nonce(self, action):
        self.fixture.https.bad = True
        with self.assertRaisesRegex(subject.Stop, 'HTTPS_SERVED_CONFIGURATION_CHANGED'):
            self.fixture.engine.invoke(action)
        self.fixture.https.bad = False

    def probes(self):
        return list(self.root.glob('go-erp-readiness-*.php')) + list(
            (self.root / 'public').glob('go-erp-readiness-*.php'))

    def reinstall(self, digest):
        subject.Activation._https = self.original_https
        subject.Activation._perform = self.original_perform
        subject.HTTPSClient.get = self.original_get
        adapter.install_adapter(types.SimpleNamespace(modules={'activation': subject}),
                                PINNED, digest, adapter.ADDRESS)

    def enabled_https_action(self):
        self.fixture.ready()
        self.fixture.engine.invoke(self.fixture.action('activation_flag'))
        cache = self.fixture.engine.invoke(self.fixture.action('activation_cache'))
        self.fixture.engine.invoke(self.fixture.action('activation_fpm',
            {'cache_sha256': cache['result']['cache_sha256'], 'route_cache_sha256': None}))
        return self.fixture.action('activation_https')

    def other_effects(self):
        cache = self.root / 'bootstrap/cache/config.php'
        return (self.fixture.env.read_bytes(), self.fixture.env.stat().st_ino,
                cache.read_bytes(), cache.stat().st_ino,
                self.fixture.runner.calls.count(('php', 'config')),
                self.fixture.runner.calls.count(('systemctl', 'reload')),
                subject.digest(self.fixture.handler['configuration']))

    def archive_then_crash(self, action):
        original = adapter.retire_stale_probe

        def crash(*args):
            original(*args)
            raise fixtures.Crash()

        with patch.object(adapter, 'retire_stale_probe', side_effect=crash):
            with self.assertRaises(fixtures.Crash):
                self.fixture.engine.recover(action)
        folder = self.folder(action)
        self.assertFalse((folder / 'probe.json').exists())
        self.assertTrue(list(folder.glob('site-retired-probe-*.json')))
        return folder

    def test_root_handler_binding_remains_original_and_public_witness_is_cleaned(self):
        before = dict(self.fixture.handler['configuration'])
        env = self.fixture.env.read_bytes()
        action = self.action()
        receipt = self.fixture.engine.invoke(action)
        self.assertTrue(receipt['result']['served_configuration_verified'])
        self.assertEqual(before, self.fixture.handler['configuration'])
        self.assertEqual(env, self.fixture.env.read_bytes())
        self.assertEqual([], self.probes())
        provenance = self.fixture.engine._load(self.folder(action) / 'site-https-adapter.json')
        self.assertEqual(str(self.root), provenance['documentroot'])
        self.assertEqual(str(self.root / 'public'), provenance['probe_directory'])
        self.assertEqual(subject.digest(before), provenance['original_handler_configuration_sha256'])
        self.assertEqual(adapter.ACTIVATION_SHA, provenance['original_activation_sha256'])
        self.assertEqual('7' * 64, provenance['adapter_sha256'])

    def test_failed_probe_can_retry_after_only_its_stale_ownership_is_retired(self):
        action = self.action()
        self.fail_nonce(action)
        folder = self.folder(action)
        pending = (folder / 'pending.json').read_bytes()
        old_owned = self.fixture.engine._load(folder / 'probe.json')
        self.assertEqual([], self.probes())
        self.assertFalse((folder / 'complete.json').exists())
        result = self.fixture.engine.invoke(action)
        self.assertTrue(result['result']['healthy'])
        archived = list(folder.glob('site-retired-probe-*.json'))
        self.assertEqual(1, len(archived))
        self.assertEqual(old_owned, self.fixture.engine._load(archived[0]))
        self.assertEqual(pending, (folder / 'pending.json').read_bytes())
        self.assertEqual([], self.probes())

    def test_an_existing_probe_file_is_never_deleted_or_adopted_by_retirement(self):
        action = self.action()
        self.fail_nonce(action)
        folder = self.folder(action)
        pending = self.fixture.engine._load(folder / 'pending.json')
        p = self.root / pending['name']
        p.write_bytes(b'peer-owned file\n')
        p.chmod(0o640)
        owned = (folder / 'probe.json').read_bytes()
        with self.assertRaisesRegex(adapter.Stop, 'EXISTING_PROBE_REVIEW_REQUIRED'):
            self.fixture.engine.invoke(action)
        self.assertEqual(b'peer-owned file\n', p.read_bytes())
        self.assertEqual(owned, (folder / 'probe.json').read_bytes())
        self.assertEqual([], list(folder.glob('site-retired-probe-*.json')))

    def test_wrong_program_ownership_hash_blocks_without_removing_record(self):
        action = self.action()
        self.fail_nonce(action)
        folder = self.folder(action)
        path = folder / 'probe.json'
        row = self.fixture.engine._load(path)
        row['sha256'] = '0' * 64
        path.write_bytes(subject.canonical(row))
        path.chmod(0o600)
        with self.assertRaisesRegex(adapter.Stop, 'STALE_PROBE_OWNERSHIP_CHANGED'):
            self.fixture.engine.invoke(action)
        self.assertEqual(row, self.fixture.engine._load(path))
        self.assertFalse((folder / 'complete.json').exists())

    def test_live_routing_change_blocks_before_success_receipt(self):
        action = self.action()
        original = self.fixture.https.get

        def change(path, token):
            response = original(path, token)
            (self.root / '.htaccess').write_bytes(b'# concurrent cPanel regeneration\n')
            return response

        with patch.object(self.fixture.https, 'get', side_effect=change):
            with self.assertRaisesRegex(adapter.Stop, 'ROUTING_CHANGED_DURING_HTTPS'):
                self.fixture.engine.invoke(action)
        folder = self.folder(action)
        self.assertFalse((folder / 'complete.json').exists())
        self.assertEqual([], self.probes())
        self.assertFalse(subject.env_enabled(self.fixture.env.read_bytes()))

    def test_original_handler_digest_check_cannot_be_rebound(self):
        self.fixture.handler['configuration']['files']['pool'] = 'b' * 64
        with self.assertRaisesRegex(subject.Stop, 'PHP_HANDLER_CHANGED'):
            self.fixture.engine.invoke(self.action())
        self.assertEqual([], self.probes())

    def test_complete_activation_keeps_handler_digest_and_original_effect_contract(self):
        initial = subject.digest(self.fixture.handler['configuration'])
        self.fixture.ready()
        self.fixture.engine.invoke(self.fixture.action('activation_flag'))
        cache = self.fixture.engine.invoke(self.fixture.action('activation_cache'))
        self.fixture.engine.invoke(self.fixture.action('activation_fpm',
            {'cache_sha256': cache['result']['cache_sha256'], 'route_cache_sha256': None}))
        receipt = self.fixture.engine.invoke(self.fixture.action('activation_https'))
        self.assertTrue(receipt['result']['served_configuration_verified'])
        self.assertTrue(receipt['result']['workspace_enabled'])
        self.assertFalse(receipt['result']['local'])
        self.assertEqual(initial, receipt['result']['php_handler_configuration_sha256'])
        self.assertEqual(initial, subject.digest(self.fixture.handler['configuration']))
        self.assertEqual(1, self.fixture.runner.calls.count(('systemctl', 'reload')))
        self.assertEqual(1, self.fixture.runner.calls.count(('php', 'config')))
        self.assertEqual([], self.probes())

    def test_completed_readiness_replay_rechecks_live_routing_before_completion(self):
        action = self.action()
        first = self.fixture.engine.invoke(action)
        folder = self.folder(action)
        original_receipt = (folder / 'complete.json').read_bytes()
        # An unchanged completed action can replay without a new network request.
        self.assertEqual(first, self.fixture.engine.invoke(action))
        calls = len(self.fixture.https.calls)
        (self.root / '.htaccess').write_bytes(b'# a later route change\n')
        with self.assertRaisesRegex(adapter.Stop, 'COMPLETED_HTTPS_ADAPTER_BINDING_CHANGED'):
            self.fixture.engine.invoke(action)
        self.assertEqual(calls, len(self.fixture.https.calls))
        self.assertEqual(original_receipt, (folder / 'complete.json').read_bytes())

    def test_enabled_https_failure_recovers_without_replaying_other_activation_effects(self):
        action = self.enabled_https_action()
        before = self.other_effects()
        self.fail_nonce(action)
        folder = self.folder(action)
        pending = (folder / 'pending.json').read_bytes()
        recovered = self.fixture.engine.recover(action)
        self.assertEqual('complete', recovered['status'])
        self.assertTrue(recovered['receipt']['result']['workspace_enabled'])
        self.assertTrue(recovered['receipt']['result']['served_configuration_verified'])
        self.assertEqual(before, self.other_effects())
        self.assertEqual(pending, (folder / 'pending.json').read_bytes())
        self.assertEqual([], self.probes())
        retries = list(folder.glob('site-https-retry-*.json'))
        self.assertEqual(1, len(retries))
        proof = self.fixture.engine._load(retries[0])
        self.assertEqual(subject.digest(action), proof['action_sha256'])
        self.assertFalse(proof['other_activation_effects_replayed'])

    def test_two_failed_https_recoveries_can_then_succeed_without_repeating_fpm_or_flag(self):
        action = self.enabled_https_action()
        self.fail_nonce(action)
        before = self.other_effects()
        self.fixture.https.bad = True
        for _ in range(2):
            self.assertEqual('unknown', self.fixture.engine.recover(action)['status'])
            self.assertEqual(before, self.other_effects())
            self.assertEqual([], self.probes())
            self.assertFalse((self.folder(action) / 'complete.json').exists())
        self.fixture.https.bad = False
        self.assertEqual('complete', self.fixture.engine.recover(action)['status'])
        self.assertEqual(before, self.other_effects())
        self.assertEqual([], self.probes())

    def test_crash_after_stale_retirement_recovers_from_archive_and_original_pending(self):
        action = self.enabled_https_action()
        self.fail_nonce(action)
        before = self.other_effects()
        pending = (self.folder(action) / 'pending.json').read_bytes()
        self.archive_then_crash(action)
        self.assertEqual('complete', self.fixture.engine.recover(action)['status'])
        self.assertEqual(before, self.other_effects())
        self.assertEqual(pending, (self.folder(action) / 'pending.json').read_bytes())

    def test_recovery_rejects_missing_ownership_evidence_without_creating_orphan_probe(self):
        action = self.enabled_https_action()
        self.fail_nonce(action)
        before = self.other_effects()
        folder = self.folder(action)
        (folder / 'probe.json').unlink()
        with self.assertRaisesRegex(adapter.Stop, 'RETRY_OWNERSHIP_ARCHIVE_REQUIRED'):
            self.fixture.engine.recover(action)
        self.assertFalse((folder / 'complete.json').exists())
        self.assertEqual([], self.probes())
        self.assertEqual(before, self.other_effects())

    def test_recovery_rejects_existing_probe_at_either_possible_path(self):
        action = self.enabled_https_action()
        self.fail_nonce(action)
        before = self.other_effects()
        folder = self.folder(action)
        name = self.fixture.engine._load(folder / 'pending.json')['name']
        owned = (folder / 'probe.json').read_bytes()
        for directory in (self.root, self.root / 'public'):
            with self.subTest(directory=directory.name):
                p = directory / name
                p.write_bytes(b'foreign witness\n')
                p.chmod(0o640)
                with self.assertRaisesRegex(adapter.Stop, 'EXISTING_PROBE_REVIEW_REQUIRED'):
                    self.fixture.engine.recover(action)
                self.assertEqual(b'foreign witness\n', p.read_bytes())
                self.assertEqual(owned, (folder / 'probe.json').read_bytes())
                p.unlink()
        self.assertEqual(before, self.other_effects())
        self.assertFalse((folder / 'complete.json').exists())

    def test_recovery_rejects_changed_pending_action_or_program_and_missing_pending(self):
        action = self.enabled_https_action()
        self.fail_nonce(action)
        before = self.other_effects()
        folder = self.folder(action)
        path = folder / 'pending.json'
        pending = self.fixture.engine._load(path)
        for key, value, code in (('action_sha256', '0' * 64, 'STALE_PROBE_INTENT_CHANGED'),
                                 ('nonce', '0' * 64, 'STALE_PROBE_OWNERSHIP_CHANGED')):
            with self.subTest(key=key):
                changed = {**pending, key: value}
                path.write_bytes(subject.canonical(changed))
                with self.assertRaisesRegex(adapter.Stop, code):
                    self.fixture.engine.recover(action)
        path.unlink()
        with self.assertRaisesRegex(adapter.Stop, 'STALE_PROBE_INTENT_CHANGED'):
            self.fixture.engine.recover(action)
        self.assertEqual(before, self.other_effects())
        self.assertEqual([], self.probes())
        self.assertFalse((folder / 'complete.json').exists())

    def test_recovery_rejects_archive_content_digest_change(self):
        action = self.enabled_https_action()
        self.fail_nonce(action)
        before = self.other_effects()
        folder = self.archive_then_crash(action)
        path = next(folder.glob('site-retired-probe-*.json'))
        owned = self.fixture.engine._load(path)
        owned['inode'][1] += 1
        path.write_bytes(subject.canonical(owned))
        with self.assertRaisesRegex(adapter.Stop, 'RETRY_ARCHIVE_DIGEST_CHANGED'):
            self.fixture.engine.recover(action)
        self.assertEqual(before, self.other_effects())
        self.assertFalse((folder / 'complete.json').exists())
        self.assertEqual([], self.probes())

    def test_recovery_rejects_noncanonical_archived_record(self):
        action = self.enabled_https_action()
        self.fail_nonce(action)
        before = self.other_effects()
        folder = self.archive_then_crash(action)
        path = next(folder.glob('site-retired-probe-*.json'))
        path.write_bytes(path.read_bytes() + b' ')
        self.assertEqual('unknown', self.fixture.engine.recover(action)['status'])
        self.assertEqual(before, self.other_effects())
        self.assertFalse((folder / 'complete.json').exists())
        self.assertEqual([], self.probes())

    def test_exact_historical_completed_supplement_is_retained_with_separate_upgrade(self):
        self.reinstall(adapter.PRIOR_ADAPTER_SHA)
        action = self.action()
        first = self.fixture.engine.invoke(action)
        folder = self.folder(action)
        old_bytes = (folder / 'site-https-adapter.json').read_bytes()
        old = self.fixture.engine._load(folder / 'site-https-adapter.json')
        self.reinstall('7' * 64)
        self.assertEqual(first, self.fixture.engine.invoke(action))
        self.assertEqual(old_bytes, (folder / 'site-https-adapter.json').read_bytes())
        upgrade = self.fixture.engine._load(folder / ('site-https-adapter-upgrade-' + '7' * 64 + '.json'))
        self.assertEqual(subject.digest(old), upgrade['predecessor_record_sha256'])
        self.assertEqual('7' * 64, upgrade['current']['adapter_sha256'])
        self.assertEqual(old['routing'], upgrade['current']['routing'])

    def test_exact_historical_incomplete_supplement_upgrades_then_recovers_https_only(self):
        self.reinstall(adapter.PRIOR_ADAPTER_SHA)
        action = self.enabled_https_action()
        self.fail_nonce(action)
        before = self.other_effects()
        folder = self.folder(action)
        old_bytes = (folder / 'site-https-adapter.json').read_bytes()
        self.reinstall('7' * 64)
        self.assertEqual('complete', self.fixture.engine.recover(action)['status'])
        self.assertEqual(before, self.other_effects())
        self.assertEqual(old_bytes, (folder / 'site-https-adapter.json').read_bytes())
        self.assertTrue((folder / ('site-https-adapter-upgrade-' + '7' * 64 + '.json')).exists())

    def test_historical_supplement_with_changed_routing_is_not_upgraded(self):
        self.reinstall(adapter.PRIOR_ADAPTER_SHA)
        action = self.action()
        self.fixture.engine.invoke(action)
        folder = self.folder(action)
        old_bytes = (folder / 'site-https-adapter.json').read_bytes()
        (self.root / '.htaccess').write_bytes(b'# newer cPanel routing\n')
        self.reinstall('7' * 64)
        with self.assertRaisesRegex(adapter.Stop, 'COMPLETED_HTTPS_ADAPTER_BINDING_CHANGED'):
            self.fixture.engine.invoke(action)
        self.assertEqual(old_bytes, (folder / 'site-https-adapter.json').read_bytes())
        self.assertEqual([], list(folder.glob('site-https-adapter-upgrade-*.json')))

    def test_unknown_predecessor_adapter_hash_is_not_accepted(self):
        self.reinstall('6' * 64)
        action = self.action()
        self.fixture.engine.invoke(action)
        self.reinstall('7' * 64)
        with self.assertRaisesRegex(adapter.Stop, 'COMPLETED_HTTPS_ADAPTER_BINDING_CHANGED'):
            self.fixture.engine.invoke(action)

    def test_retry_permission_is_never_extended_to_disabled_readiness(self):
        action = self.action()
        self.fail_nonce(action)
        with self.assertRaisesRegex(adapter.Stop, 'ONLY_HTTPS_RETRY_ALLOWED'):
            self.fixture.engine.recover(action)
        self.assertFalse(subject.env_enabled(self.fixture.env.read_bytes()))
        self.assertEqual([], self.probes())

    def test_original_source_bytes_must_match_before_adapting(self):
        with self.assertRaisesRegex(adapter.Stop, 'ORIGINAL_ACTIVATION_CHANGED'):
            adapter.install_adapter(types.SimpleNamespace(modules={'activation': subject}),
                                    PINNED + b'\n', '7' * 64, adapter.ADDRESS)

    def test_tls_uses_local_ip_with_original_verified_context_sni_and_request(self):
        context = Mock()
        socket = Mock()
        response = Mock(status=200)
        response.getheader.return_value = 'application/json; charset=utf-8'
        response.read.return_value = b'{"nonce":"same"}'
        seen = []

        def request(connection, method, path, headers):
            connection.connect()
            seen.append((method, path, headers))

        path, token = '/go-erp-readiness-' + 'a' * 32 + '.php', 'b' * 64
        with patch.object(subject.ssl, 'create_default_context', return_value=context) as tls, \
             patch.object(subject.socket, 'create_connection', return_value=socket) as tcp, \
             patch.object(subject.http.client.HTTPSConnection, 'request', new=request), \
             patch.object(subject.http.client.HTTPSConnection, 'getresponse', return_value=response), \
             patch.object(subject.http.client.HTTPSConnection, 'close'):
            self.assertEqual({'nonce': 'same'}, subject.HTTPSClient().get(path, token))
        tls.assert_called_once_with()
        tcp.assert_called_once_with((adapter.ADDRESS, 443), timeout=15)
        context.wrap_socket.assert_called_once_with(socket, server_hostname='fasakhaninja.com')
        self.assertEqual([('GET', path, {'X-Go-Erp-Readiness': token,
            'Cache-Control': 'no-cache', 'Accept': 'application/json'})], seen)

    def test_tls_rejects_redirect_and_non_json_response(self):
        response = Mock(status=302)
        response.getheader.return_value = 'text/html'
        with patch.object(subject.ssl, 'create_default_context', return_value=Mock()), \
             patch.object(subject.http.client.HTTPSConnection, 'request'), \
             patch.object(subject.http.client.HTTPSConnection, 'getresponse', return_value=response), \
             patch.object(subject.http.client.HTTPSConnection, 'close'):
            with self.assertRaisesRegex(subject.Stop, 'HTTPS_NOT_HEALTHY'):
                subject.HTTPSClient().get('/go-erp-readiness-' + 'a' * 32 + '.php', 'b' * 64)
        response.read.assert_not_called()


class LocalAddressTest(unittest.TestCase):
    def engine(self, ip):
        engine = Mock()
        engine.read_file.return_value = (b'ip: ' + ip.encode() + b'\n', object())
        return engine

    def test_cpanel_ip_must_also_be_present_on_local_interface(self):
        engine = self.engine(adapter.ADDRESS)
        output = types.SimpleNamespace(stdout=json.dumps([
            {'addr_info': [{'local': '127.0.0.1'}]},
            {'addr_info': [{'local': adapter.ADDRESS}]}]).encode())
        with patch.object(adapter.shutil, 'which', return_value='/usr/sbin/ip'), \
             patch.object(adapter.subprocess, 'run', return_value=output) as run:
            self.assertEqual(adapter.ADDRESS, adapter.local_address(engine))
        self.assertEqual(['/usr/sbin/ip', '-j', '-4', 'address', 'show'], run.call_args.args[0])
        engine.read_file.assert_called_once_with(adapter.USERDATA, 2 * 1024**2,
                                                uid=0, modes=(0o600, 0o640, 0o644))

    def test_another_cpanel_address_is_rejected_before_any_network_inspection(self):
        with patch.object(adapter.subprocess, 'run') as run:
            with self.assertRaisesRegex(adapter.Stop, 'CPANEL_ADDRESS_CHANGED'):
                adapter.local_address(self.engine('192.0.2.7'))
        run.assert_not_called()

    def test_cpanel_address_missing_from_local_interfaces_is_rejected(self):
        output = types.SimpleNamespace(stdout=json.dumps([
            {'addr_info': [{'local': '127.0.0.1'}, {'local': '192.0.2.7'}]}]).encode())
        with patch.object(adapter.shutil, 'which', return_value='/usr/sbin/ip'), \
             patch.object(adapter.subprocess, 'run', return_value=output):
            with self.assertRaisesRegex(adapter.Stop, 'VHOST_ADDRESS_NOT_LOCAL'):
                adapter.local_address(self.engine(adapter.ADDRESS))


class FailureCodesTest(unittest.TestCase):
    def test_exact_known_operator_code_is_preserved_without_traceback(self):
        try:
            raise RuntimeError('GO_ERP_SHARED_WORKSPACE_ACTIVATION_HTTPS_NOT_HEALTHY')
        except RuntimeError as error:
            self.assertEqual(['GO_ERP_SHARED_WORKSPACE_ACTIVATION_HTTPS_NOT_HEALTHY'],
                             adapter.failure_codes(error))

    def test_unexpected_error_omits_message_filename_and_local_values(self):
        namespace = {}
        exec(compile("def fail():\n    private_value = 'local-secret-4825'\n"
                     "    raise ValueError('password=message-secret-7256')\n",
                     '/private/customer-sensitive-directory/operator.py', 'exec'), namespace)
        try:
            namespace['fail']()
        except ValueError as error:
            codes = adapter.failure_codes(error)
        self.assertEqual('GO_ERP_SITE_HTTPS_ERROR_VALUEERROR', codes[0])
        self.assertTrue(any('_FAIL_LINE_' in value for value in codes))
        self.assertTrue(all(re.fullmatch(r'GO_ERP_[A-Z0-9_]+', value) for value in codes))
        rendered = '\n'.join(codes)
        for private in ('message-secret', 'local-secret', 'customer-sensitive', 'operator.py', 'password='):
            self.assertNotIn(private, rendered)

    def test_telemetry_has_at_most_eight_frames_and_rejects_malformed_public_code(self):
        def recurse(depth):
            if depth:
                return recurse(depth - 1)
            raise RuntimeError('GO_ERP_VALID_PREFIX secret=never-display')

        try:
            recurse(14)
        except RuntimeError as error:
            codes = adapter.failure_codes(error)
        self.assertEqual('GO_ERP_SITE_HTTPS_ERROR_RUNTIMEERROR', codes[0])
        self.assertEqual(9, len(codes))
        self.assertTrue(all('_RECURSE_LINE_' in value for value in codes[1:]))
        self.assertNotIn('secret', '\n'.join(codes))


class RunnerPreflightTest(unittest.TestCase):
    CANDIDATE = 'd1976ce02c553939032241e7e724d26a9bbe7190'

    def run_guard(self, phase, pending, candidate=None):
        source = (ROOT / 'deployment/run_go_erp_site_https_root.sh').read_text()
        match = re.search(r"^python3[^\n]*<<'PY'\n(.*?)^PY$", source, re.M | re.S)
        self.assertIsNotNone(match)
        desired = {'commit': candidate or self.CANDIDATE, 'version': '0.4.4-preview.60'}
        state_path = '/var/lib/go-erp-live-updates/state/current.json'
        flow_path = ('/var/lib/go-erp-manual-updates/private/shared-workspace-root-'
                     '052e69008da7e330815b376824cd8afc18cf04c1.json')
        documents = {state_path: {'desired': desired},
                     flow_path: {'activation': {'phase': phase}, 'pending_activation': pending}}

        def read(path, *args, **kwargs):
            self.assertIn(path, documents)
            return io.StringIO(json.dumps(documents[path]))

        with patch('builtins.open', side_effect=read), \
             patch.object(sys, 'argv', ['-', self.CANDIDATE]), \
             contextlib.redirect_stdout(io.StringIO()):
            exec(compile(match[1], '<actual-runner-preflight>', 'exec'), {})

    def test_fpm_ready_with_pending_https_is_allowed(self):
        self.run_guard('fpm-ready', {'name': 'activation_https'})

    def test_active_without_pending_is_allowed(self):
        self.run_guard('active', None)

    def test_earlier_activation_state_is_rejected(self):
        with self.assertRaisesRegex(SystemExit, 'LATE_ACTIVATION_STATE_REVIEW_REQUIRED'):
            self.run_guard('gates-accepted', None)

    def test_pending_non_https_effect_is_rejected(self):
        with self.assertRaisesRegex(SystemExit, 'LATE_ACTIVATION_STATE_REVIEW_REQUIRED'):
            self.run_guard('fpm-ready', {'name': 'activation_fpm'})

    def test_active_with_pending_https_is_rejected(self):
        with self.assertRaisesRegex(SystemExit, 'LATE_ACTIVATION_STATE_REVIEW_REQUIRED'):
            self.run_guard('active', {'name': 'activation_https'})

    def test_wrong_candidate_is_rejected(self):
        with self.assertRaisesRegex(SystemExit, 'CANDIDATE_NOT_PREVIEW_60'):
            self.run_guard('fpm-ready', {'name': 'activation_https'}, candidate='0' * 40)


if __name__ == '__main__':
    unittest.main()
