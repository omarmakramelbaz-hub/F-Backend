"""Exercise the exact pinned handler with queued, unaccepted UNIX peers.

The /proc fixture models Linux unix_stream_connect before unix_accept grafts
the queued child socket. No real FPM service or application data is changed.
"""
import ast
import hashlib
import importlib.util
from pathlib import Path
from types import ModuleType, SimpleNamespace
import unittest
from unittest.mock import Mock, patch

BASE = Path(__file__).resolve().parents[1]
FIXTURE = BASE / 'tests/fixtures/go_erp_desktop_performance/php_handler.py'
spec = importlib.util.spec_from_file_location('successor_php_adapter',
    BASE / 'deployment/upgrade_go_erp_desktop_audit_successor_root.py')
roll = importlib.util.module_from_spec(spec); spec.loader.exec_module(roll)
ORIGINAL = FIXTURE.read_bytes()


def module(raw, name):
    result = ModuleType(name)
    exec(compile(raw, '<' + name + '>', 'exec'), result.__dict__)
    return result


class ProcFixture:
    pid = 123
    inode = '101'
    socket = '/opt/cpanel/ea-php82/root/usr/var/run/php-fpm/test.sock'

    def __init__(self, handler, peers=(), *, listener=True, listener_inode='101',
                 owned=True, executable=None, extra_listener=False):
        self.handler = handler
        self.rows = ['Num RefCount Protocol Flags Type St Inode Path']
        if listener:
            self.rows.append('000000: 00000002 00000000 00010000 0001 01 '
                             + listener_inode + ' ' + self.socket)
        if extra_listener:
            self.rows.append('000002: 00000002 00000000 00010000 0001 01 202 ' + self.socket)
        for flags, kind, state, inode in peers:
            self.rows.append('000001: 00000003 00000000 ' + flags + ' ' + kind
                             + ' ' + state + ' ' + inode + ' ' + self.socket)
        self.owned = owned
        self.executable = handler.FPM_EXE if executable is None else executable

    def read_text(self, path):
        assert path == '/proc/net/unix'
        return '\n'.join(self.rows) + '\n'

    def readlink(self, path):
        if path == '/proc/123/exe': return self.executable
        assert path == '/proc/123/fd/7'
        return 'socket:[101]' if self.owned else 'socket:[999]'

    def listdir(self, path):
        assert path == '/proc/123/fd'
        return ['7']

    def io(self):
        return {'read_text': self.read_text, 'readlink': self.readlink, 'listdir': self.listdir}

    def verify(self):
        return self.handler.live_socket(self.pid, self.socket, **self.io())


class EngineFixture:
    def __init__(self, handler):
        self.handler = handler
        self.fpm_active = True
        h = handler
        self.files = {
            str(h.USERDATA): ('documentroot: ' + str(h.ROOT) + '\nservername: fasakhaninja.com\n'
                'user: fasakha\ngroup: fasakha\nphpversion: ea-php82\n').encode(),
            str(h.FPM_YAML): b'_is_present: 1\n',
            str(h.FPM_CONFIG): ('include=' + str(h.POOL.parent / '*.conf') + '\n').encode(),
            str(h.POOL): ('[fasakhaninja_com]\nuser=fasakha\ngroup=fasakha\nlisten='
                + ProcFixture.socket + '\n').encode(),
            str(h.APACHE): ('<VirtualHost *:80>\nServerName fasakhaninja.com\nDocumentRoot '
                + str(h.ROOT) + '\n<FilesMatch "\\.php$">\nSetHandler "proxy:unix:'
                + ProcFixture.socket + '|fcgi://fasakhaninja.com"\n</FilesMatch>\n</VirtualHost>\n').encode(),
        }

    def read_file(self, path, maximum, *, uid, modes):
        assert uid == 0 and modes == (0o600, 0o640, 0o644)
        raw = self.files[str(path)]; assert len(raw) <= maximum
        return raw, None

    def run(self, arguments):
        if arguments[:2] == ['/usr/bin/systemctl', 'show']:
            unit = arguments[2]
            if unit == 'ea-php82-php-fpm.service':
                return 'ActiveState=' + ('active' if self.fpm_active else 'inactive') + '\nCanReload=yes\nMainPID=123\n'
            if unit == 'httpd.service': return 'ActiveState=active\nCanReload=yes\n'
            assert unit == 'lsws.service'; return 'ActiveState=inactive\n'
        if arguments == ['/usr/sbin/httpd', '-t', '-D', 'DUMP_VHOSTS']:
            return 'port 80 namevhost fasakhaninja.com (' + str(self.handler.APACHE) + ':1)\n'
        assert arguments == ['/usr/sbin/httpd', '-M']
        return ' proxy_module (shared)\n proxy_fcgi_module (shared)\n'


class HandlerSocket(unittest.TestCase):
    def setUp(self):
        self.old = module(ORIGINAL, 'frozen_original_handler')
        self.new = module(roll.php_handler_bytes(ORIGINAL), 'derived_reviewed_handler')

    def test_exact_original_rejects_queued_inode_zero_while_derived_proves_same_listener(self):
        self.assertEqual(ProcFixture(self.old).verify(), '101')
        queued = [('00000000', '0001', '02', '0')]
        with self.assertRaisesRegex(self.old.Stop, 'PHP_HANDLER_REVIEW_REQUIRED'):
            ProcFixture(self.old, queued).verify()
        self.assertEqual(ProcFixture(self.new, queued).verify(), '101')

    def test_valid_positive_inode_peers_and_all_original_peer_states_remain_accepted(self):
        for state in ('01', '02', '03', '04'):
            with self.subTest(state=state):
                peers = [('00000000', '0001', state, '303')]
                self.assertEqual(ProcFixture(self.old, peers).verify(), '101')
                self.assertEqual(ProcFixture(self.new, peers).verify(), '101')

    def test_zero_listener_and_peers_without_listener_cannot_prove_a_socket(self):
        for kwargs in ({'listener_inode': '0'}, {'listener': False}):
            with self.subTest(kwargs=kwargs), self.assertRaises(self.new.Stop):
                ProcFixture(self.new, [('00000000', '0001', '02', '0')], **kwargs).verify()

    def test_noncanonical_peer_inodes_flags_type_and_states_are_rejected(self):
        bad = [
            ('00000000', '0001', '02', '-1'), ('00000000', '0001', '02', '00'),
            ('00000000', '0001', '02', 'bad'), ('00000000', '0002', '02', '0'),
            ('00000000', '0001', '05', '0'), ('00010001', '0001', '01', '303'),
        ]
        for peer in bad:
            with self.subTest(peer=peer), self.assertRaises(self.new.Stop):
                ProcFixture(self.new, [peer]).verify()

    def test_a_positive_unique_listener_must_still_belong_to_the_exact_master(self):
        for kwargs in ({'owned': False}, {'executable': '/unrelated/php-fpm'}, {'extra_listener': True}):
            with self.subTest(kwargs=kwargs), self.assertRaises(self.new.Stop):
                ProcFixture(self.new, [('00000000', '0001', '02', '0')], **kwargs).verify()

    def test_configuration_and_full_verify_result_are_identical_for_the_same_listener(self):
        old = self.old.verify(EngineFixture(self.old), runtime_io=ProcFixture(self.old).io())
        with self.assertRaisesRegex(self.old.Stop, 'PHP_HANDLER_REVIEW_REQUIRED'):
            self.old.verify(EngineFixture(self.old), runtime_io=ProcFixture(self.old,
                [('00000000', '0001', '02', '0')]).io())
        new = self.new.verify(EngineFixture(self.new), runtime_io=ProcFixture(self.new,
            [('00000000', '0001', '02', '0')]).io())
        self.assertEqual(new, old)

    def test_full_verify_still_rejects_changed_user_vhost_handler_and_inactive_fpm(self):
        for fault in ('user', 'vhost', 'service'):
            engine = EngineFixture(self.new)
            if fault == 'user':
                engine.files[str(self.new.USERDATA)] = engine.files[str(self.new.USERDATA)].replace(b'user: fasakha', b'user: foreign')
            elif fault == 'vhost':
                engine.files[str(self.new.APACHE)] = engine.files[str(self.new.APACHE)].replace(b'proxy:unix:', b'foreign:unix:')
            else: engine.fpm_active = False
            with self.subTest(fault=fault), self.assertRaises(self.new.Stop):
                self.new.verify(engine, runtime_io=ProcFixture(self.new,
                    [('00000000', '0001', '02', '0')]).io())

    def test_original_and_derived_hashes_are_both_required_and_other_functions_are_unchanged(self):
        self.assertEqual(hashlib.sha256(ORIGINAL).hexdigest(), roll.PHP_HANDLER_ORIGINAL_SHA)
        derived = roll.php_handler_bytes(ORIGINAL)
        self.assertEqual(hashlib.sha256(derived).hexdigest(), roll.PHP_HANDLER_DERIVED_SHA)
        for raw in (ORIGINAL + b'\n', ORIGINAL.replace(b'PHP82', b'PHP83')):
            with self.assertRaisesRegex(roll.Stop, 'PHP_HANDLER_ORIGINAL_CHANGED'):
                roll.php_handler_bytes(raw)
        old = ast.parse(ORIGINAL); new = ast.parse(derived)
        before = [ast.dump(n, include_attributes=False) for n in old.body
                  if not isinstance(n, ast.FunctionDef) or n.name != 'live_socket']
        after = [ast.dump(n, include_attributes=False) for n in new.body
                 if not isinstance(n, ast.FunctionDef) or n.name != 'live_socket']
        self.assertEqual(after, before)


class HandlerInstallation(unittest.TestCase):
    def fixture(self):
        value = object.__new__(roll.Successor)
        value.package = SimpleNamespace(file=Mock(return_value=ORIGINAL))
        value.e = object(); value.o = SimpleNamespace(_handler=Mock())
        loaded = []
        def authenticated_load(raw, name, expected):
            self.assertEqual(hashlib.sha256(raw).hexdigest(), expected)
            result = module(raw, name); loaded.append(result)
            return result
        api = SimpleNamespace(LEGACY_RELEASE=roll.PHP_HANDLER_RELEASE,
            PHP_HANDLER_SHA=roll.PHP_HANDLER_ORIGINAL_SHA, load=Mock(side_effect=authenticated_load))
        value.old = SimpleNamespace(root_api=api)
        return value, api, loaded

    def test_adapter_is_instance_local_and_authenticates_before_installing(self):
        value, api, loaded = self.fixture(); old = value.o._handler
        value._install_php_handler()
        value.package.file.assert_called_once_with('deployment/go_erp_navigation_php_root.py', roll.PHP_HANDLER_RELEASE)
        self.assertEqual(len(loaded), 1); old.assert_not_called()
        self.assertEqual(api.load.call_args.args[2], roll.PHP_HANDLER_DERIVED_SHA)
        expected = {'configuration': {'unchanged': True}}
        with patch.object(loaded[0], 'verify', return_value=expected) as verify:
            self.assertIs(value.o._handler(), expected)
        verify.assert_called_once_with(value.e)

    def test_real_handler_refusal_remains_a_fixed_public_failure(self):
        value, _api, loaded = self.fixture(); value._install_php_handler()
        with patch.object(loaded[0], 'verify', side_effect=loaded[0].Stop('PHP_HANDLER_REVIEW_REQUIRED')):
            with self.assertRaisesRegex(roll.Stop, '^GO_ERP_DESKTOP_AUDIT_SUCCESSOR_PHP_HANDLER_REVIEW_REQUIRED$'):
                value.o._handler()

    def test_frozen_root_binding_cannot_be_substituted(self):
        value, api, _loaded = self.fixture(); api.PHP_HANDLER_SHA = '0' * 64
        with self.assertRaisesRegex(roll.Stop, 'PHP_HANDLER_ROOT_BINDING_CHANGED'):
            value._install_php_handler()
        value.package.file.assert_not_called(); api.load.assert_not_called()

    def test_adapter_installs_before_the_first_successor_review_and_before_any_run(self):
        order = []
        package = SimpleNamespace(revision=roll.ROOT_RELEASE)
        operator = SimpleNamespace(engine=object(), io=object())
        roles = {name: object() for name in ('current', 'payloads', 'source', 'recovery_plan', 'recovery_evidence', 'recovery_effects')}
        roles['coordinator'] = SimpleNamespace(Rollout=Mock(return_value=object()))
        roles['successor_plan'] = SimpleNamespace(NEW_CORE=roll.CORE, NEW_SEED=roll.SEED)
        with patch.object(roll.Successor, '_install_php_handler', side_effect=lambda: order.append('handler')), \
             patch.object(roll.Successor, '_review_seed', side_effect=lambda: order.append('review')):
            roll.Successor(package, operator, roles)
        self.assertEqual(order, ['handler', 'review'])


if __name__ == '__main__':
    unittest.main()
