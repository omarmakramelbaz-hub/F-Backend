"""Site adapter tests with real temporary probe/WAL files and mocked TLS transport."""
import importlib.util
import json
from pathlib import Path
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


if __name__ == '__main__':
    unittest.main()
