"""Read-only diagnostic classifications must never print private config values."""
import contextlib
import hashlib
import importlib.util
import inspect
import io
import json
import re
import shlex
import types
import unittest
from pathlib import Path
from urllib.parse import urlsplit


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'deployment/diagnose_go_erp_navigation_php_detail_root.py'
HELPER = ROOT / 'deployment/go_erp_navigation_php_root.py'
SPEC = importlib.util.spec_from_file_location('detail_handler_fixtures',
    ROOT / 'tests/test_go_erp_navigation_php_root.py')
fixtures = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(fixtures)


class DetailDiagnosticTests(unittest.TestCase):
    def diagnostic(self, change=lambda fixture: None):
        fixture = fixtures.HandlerProofTests()
        fixture.setUp()
        change(fixture)
        raw = HELPER.read_bytes()
        namespace = {'__name__': 'detail_diagnostic_fixture', '__file__': 'pinned_php_handler.py'}
        exec(compile(raw, 'pinned_php_handler.py', 'exec'), namespace)
        original_live = namespace['live_socket']
        namespace['live_socket'] = lambda pid, pool, **kwargs: original_live(
            pid, pool, **(kwargs or fixture.io))

        class FakeEngine:
            def read_file(self, name, maximum, **policy):
                return fixture.read(name, maximum, uid=0, modes=(0o600, 0o640, 0o644))

            def run(self, args):
                return fixture.engine_run(args)

        def proc_open(name, *args, **kwargs):
            self.assertEqual(name, '/proc/net/unix')
            self.assertNotIn('w', kwargs.get('mode', args[0] if args else 'r'))
            return io.StringIO(fixture.unix)

        proc_os = types.SimpleNamespace(readlink=fixture.readlink,
            listdir=lambda name: fixture.io['listdir'](name))
        body = SCRIPT.read_text()
        body = body[body.index("out = {'format':1"):].replace('e = Engine()', 'e = fake_engine')
        context = {
            'n': namespace, 'rev': 'e2ffe00c792f0c8c39c8cf017b983393ebe62c2a',
            'r': types.SimpleNamespace(stdout=raw), 'hashlib': hashlib, 'inspect': inspect,
            'json': json, 'os': proc_os, 're': re, 'shlex': shlex, 'urlsplit': urlsplit,
            'Path': Path, 'open': proc_open, 'fake_engine': FakeEngine(),
        }
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            exec(compile(body, str(SCRIPT), 'exec'), context)
        rendered = output.getvalue()
        self.assertLess(len(rendered), 24*1024)
        self.assertEqual(len(rendered.splitlines()), 1)
        result = json.loads(rendered.removeprefix('GO_ERP_PHP_HANDLER_DETAIL_DIAG '))
        self.assertTrue(result['read_only'])
        self.assertFalse(result['database_read'])
        self.assertEqual(result['helper_sha256'], hashlib.sha256(raw).hexdigest())
        self.assertFalse(any('reload' in args for args in fixture.calls))
        return result, context, fixture, rendered

    def test_valid_fixture_passes_full_and_separate_verifiers_without_mutations(self):
        result, _, fixture, rendered = self.diagnostic()
        for key in ('original_verifier', 'vhost_binding', 'live_socket', 'proxy_inventory_check'):
            self.assertTrue(result[key]['ok'], key)
        self.assertEqual(result['proxy_inventory']['count'], 0)
        self.assertTrue(result['socket_facts']['master_holds_listener'])
        self.assertEqual(result['socket_facts']['exact_listener_count'], 1)
        self.assertNotIn(fixture.socket, rendered)
        self.assertNotIn('9876', rendered)
        for args in fixture.calls:
            self.assertTrue(args[0] == '/usr/bin/systemctl' and args[1] == 'show'
                or args in (['/usr/sbin/httpd', '-t', '-D', 'DUMP_VHOSTS'],
                            ['/usr/sbin/httpd', '-M']))

    def test_proxy_exclusion_and_positive_mapping_are_distinguished_without_uri_values(self):
        secret = 'SECRET_DO_NOT_PRINT'
        def change(f):
            f.files[fixtures.subject.APACHE] = f.files[fixtures.subject.APACHE].replace(
                '</VirtualHost>', 'ProxyPass /.well-known !\n'
                'ProxyPass /'+secret+' https://'+secret+':'+secret+'@127.0.0.1:2083/'
                +secret+'?token='+secret+'#'+secret+'\n</VirtualHost>')
        result, _, _, rendered = self.diagnostic(change)
        self.assertFalse(result['original_verifier']['ok'])
        self.assertNotIn(secret, rendered)
        rows = result['proxy_inventory']['rows']
        self.assertEqual(len(rows), 2)
        self.assertTrue(rows[0]['literal_exclusion_accepted'])
        self.assertEqual(rows[0]['destination_kind'], 'EXCLUSION')
        self.assertEqual(rows[1]['destination_kind'], 'HTTPS')
        self.assertEqual(rows[1]['destination_host_kind'], 'LOOPBACK')
        self.assertEqual(rows[1]['loopback_port'], 2083)
        self.assertTrue(rows[1]['destination_credentials_present'])
        self.assertTrue(rows[1]['destination_query_present'])
        self.assertTrue(rows[1]['destination_fragment_present'])
        self.assertEqual(result['failures']['vhost_binding']['proxy']['destination_kind'], 'HTTPS')

    def test_proxy_token_options_comments_invalid_and_guards_are_bounded_labels(self):
        _, context, _, _ = self.diagnostic()
        shape = context['proxy_shape']
        self.assertEqual(shape('ProxyPass /.well-known !')['source_kind'], 'WELL_KNOWN')
        self.assertEqual(shape('ProxyPass /___proxy_subdomain_ws http://localhost:8080/')['source_kind'], 'CPANEL_PROXY_PREFIX')
        self.assertFalse(shape('ProxyPass /local ! nocanon')['literal_exclusion_accepted'])
        comment = shape('ProxyPass /local ! # PRIVATE_COMMENT')
        self.assertTrue(comment['comment_marker_present'])
        self.assertEqual(comment['trailing_argument_count'], 2)
        self.assertEqual(shape('ProxyPass "unterminated !')['tokenization'], 'INVALID')
        self.assertEqual(shape('ProxyPassMatch ^.* !')['directive'], 'ProxyPassMatch')
        result = shape('ProxyPass / !', ['ifmodule', 'PRIVATE_CONTAINER'], ['PRIVATE_MODULE'])
        self.assertEqual(result['containers'], ['ifmodule', 'OTHER'])
        self.assertEqual(result['guards'], ['OTHER'])
        self.assertNotIn('PRIVATE_', json.dumps(result))
        for scheme in ('ws', 'wss'):
            classified = shape('ProxyPass /private '+scheme+'://localhost:2080/private')
            self.assertEqual(classified['destination_kind'], scheme.upper())
            self.assertEqual(classified['destination_host_kind'], 'LOOPBACK')

    def test_proxy_inventory_covers_every_referenced_vhost_and_truncates_only_output(self):
        def change(f):
            block = f.files[fixtures.subject.APACHE]
            first = block.replace('</VirtualHost>', 'ProxyPass /.well-known !\n</VirtualHost>')
            start = len(first.splitlines()) + 1
            second = block.replace('</VirtualHost>', 'ProxyPass /private !\n'*40+'</VirtualHost>')
            f.files[fixtures.subject.APACHE] = first+second
            f.dump += 'port 443 namevhost fasakhaninja.com (/etc/apache2/conf/httpd.conf:'+str(start)+')\n'
        result, _, _, _ = self.diagnostic(change)
        inventory = result['proxy_inventory']
        self.assertEqual(inventory['reference_count'], 2)
        self.assertEqual(inventory['count'], 41)
        self.assertEqual(len(inventory['rows']), 32)
        self.assertTrue(inventory['truncated'])
        self.assertEqual(inventory['rows'][0]['vhost_ordinal'], 1)
        self.assertEqual(inventory['rows'][1]['vhost_ordinal'], 2)

    def test_socket_peer_and_listener_facts_are_distinguished(self):
        def change(f):
            f.unix += '0001: 0002 0000 00000000 0001 03 9877 '+f.socket+'\n'
        result, _, _, _ = self.diagnostic(change)
        self.assertTrue(result['live_socket']['ok'])
        self.assertEqual(result['socket_facts']['matching_row_count'], 2)
        self.assertEqual(result['socket_facts']['exact_listener_count'], 1)
        self.assertTrue(result['socket_facts']['rows'][0]['master_holds_inode'])
        self.assertFalse(result['socket_facts']['rows'][1]['master_holds_inode'])

    def test_master_holding_only_a_peer_cannot_pass_or_print_private_paths(self):
        def change(f):
            f.unix += '0001: 0002 0000 00000000 0001 03 9877 '+f.socket+'\n'
            f.fd_target = 'socket:[9877]'
        result, _, fixture, rendered = self.diagnostic(change)
        self.assertFalse(result['live_socket']['ok'])
        self.assertFalse(result['socket_facts']['master_holds_listener'])
        self.assertTrue(result['socket_facts']['rows'][1]['master_holds_inode'])
        self.assertNotIn(fixture.socket, rendered)

    def test_deleted_executable_is_reported_and_not_accepted(self):
        result, _, _, rendered = self.diagnostic(lambda f: setattr(f, 'exe', fixtures.subject.FPM_EXE+' (deleted)'))
        self.assertFalse(result['live_socket']['ok'])
        self.assertEqual(result['socket_facts']['exe_kind'], 'EXPECTED_DELETED')
        self.assertNotIn(fixtures.subject.FPM_EXE, rendered)

    def test_unknown_socket_fields_or_invalid_inode_are_classified_without_values(self):
        for before, after, field, expected in [
            ('00010000', '00020000', 'flags', '00020000'),
            ('00010000', 'SECRET_FLAGS', 'flags', 'OTHER'),
            ('0001 01 9876', '0002 01 9876', 'type', '0002'),
            ('0001 01 9876', '0001 05 9876', 'state', '05'),
            ('01 9876 ', '01 SECRET_INODE ', 'valid_inode', False),
        ]:
            with self.subTest(after=after):
                result, _, _, rendered = self.diagnostic(lambda f: setattr(f, 'unix', f.unix.replace(before, after)))
                self.assertFalse(result['live_socket']['ok'])
                self.assertEqual(result['socket_facts']['rows'][0][field], expected)
                self.assertNotIn('SECRET_', rendered)

    def test_socket_output_truncates_but_counts_all_matching_rows(self):
        def change(f):
            for index in range(40):
                f.unix += '0001: 0002 0000 00000000 0001 03 '+str(10000+index)+' '+f.socket+'\n'
        result, _, _, _ = self.diagnostic(change)
        self.assertTrue(result['live_socket']['ok'])
        self.assertEqual(result['socket_facts']['matching_row_count'], 41)
        self.assertEqual(len(result['socket_facts']['rows']), 32)
        self.assertTrue(result['socket_facts']['rows_truncated'])

    def test_disappearing_file_descriptors_do_not_prevent_listener_diagnosis(self):
        def change(f):
            readlink = f.readlink
            def raced(path):
                if path.endswith('/8'):
                    raise FileNotFoundError
                return readlink(path)
            f.readlink = raced
            f.io['readlink'] = raced
            f.io['listdir'] = lambda name: ['0', '1', '7', '8']
        result, _, _, _ = self.diagnostic(change)
        self.assertTrue(result['live_socket']['ok'])
        self.assertEqual(result['socket_facts']['fd_disappeared'], 1)
        self.assertTrue(result['socket_facts']['master_holds_listener'])

    def test_proc_or_descriptor_count_over_limit_stops_without_printing_input(self):
        for change in (lambda f: setattr(f, 'unix', 'PRIVATE_OVERSIZE'+'x'*(2*1024**2)),
                       lambda f: f.io.__setitem__('listdir', lambda name: ['7']*8193)):
            with self.subTest(change=change):
                result, _, _, rendered = self.diagnostic(change)
                self.assertFalse(result['live_socket']['ok'])
                self.assertNotIn('PRIVATE_OVERSIZE', rendered)

    def test_missing_phpversion_and_single_vhost_dump_remain_diagnosable(self):
        result, _, _, _ = self.diagnostic(lambda f: f.files.__setitem__(fixtures.subject.USERDATA,
            f.files[fixtures.subject.USERDATA].replace('phpversion: ea-php82\n', '')))
        self.assertFalse(result['original_verifier']['ok'])
        self.assertEqual(result['userdata_phpversion'], 'ABSENT')
        result, _, _, _ = self.diagnostic(lambda f: setattr(f, 'dump',
            '*:80 fasakhaninja.com (/etc/apache2/conf/httpd.conf:1)\n'))
        self.assertFalse(result['vhost_binding']['ok'])
        self.assertEqual(result['dump_shapes'], {'recognized': 0, 'single_vhost': 1})


if __name__ == '__main__':
    unittest.main()
