#!/usr/bin/env python3
"""Exercise secret input, actual Linux publication, rollback, and cache isolation."""
import contextlib
import errno
import importlib.util
import io
import json
import os
import pathlib
import pty
import select
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('wa_orders_secure_config', ROOT / 'deployment/configure_whatsapp_orders.py')
helper = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = helper
spec.loader.exec_module(helper)
PHP = os.environ.get('WA_ORDERS_CONFIG_TEST_PHP') or shutil.which('php')
KEY = 'sk-proj-' + 'T' * 32  # Synthetic credential, never sent anywhere.
WA_TOKEN = 'EAA' + 'W' * 40


def feature(current=None):
    current = current or {'enabled': False, 'message_ceiling': 95, 'event_ceiling': 201}
    orders = {'enabled': True, 'mode': 'review', 'model': 'gpt-5.6-terra', 'api_key': KEY,
            'automation_actor_id': None, 'allowed_branch_ids': [],
            'activation_message_id': (current['activation_message_id'] if current.get('enabled') is True
                                      else current['message_ceiling']),
            'activation_event_id': (current['activation_event_id'] if current.get('enabled') is True
                                    else current['event_ceiling'])}
    return {'whatsapp_orders': orders, 'whatsapp_replies': {'enabled': True, 'access_token': WA_TOKEN}}


class FakeTty(io.StringIO):
    def __init__(self, answers):
        super().__init__('')
        self.answers = iter(answers)
        self.prompts = []

    def write(self, content):
        self.prompts.append(content)
        return len(content)

    def readline(self, limit=-1):
        return next(self.answers, '')


class Fixture:
    def __init__(self, cache=False, fail=None, hook=None, current=None):
        self.temporary = tempfile.TemporaryDirectory(prefix='wa-secure-config-')
        self.home = pathlib.Path(self.temporary.name)
        self.root = self.home / 'public_html'
        self.root.mkdir()
        (self.root / '.git').mkdir()
        (self.root / 'bootstrap/cache').mkdir(parents=True)
        (self.root / '.env').write_bytes(b'APP_NAME=UnrelatedDraft\n# preserved comment\nOTHER_SECRET=literal\n')
        (self.root / '.env').chmod(0o640)
        if cache:
            (self.root / helper.CACHE_PATH).write_bytes(
                b"<?php return ['app'=>['name'=>'EffectiveLive','secret'=>'opaque'], 'whatsapp'=>['allowlist'=>['468336579702269']], 'whatsapp_orders'=>['enabled'=>false]];\n")
            (self.root / helper.CACHE_PATH).chmod(0o640)
        self.before_env = helper.secure_snapshot(self.root / '.env', os.geteuid(), self.root)
        self.before_cache = helper.secure_snapshot(self.root / helper.CACHE_PATH, os.geteuid(), self.root, True)
        self.fail, self.hook = fail, hook
        self.current = current or {'enabled': False, 'api_key': None, 'activation_message_id': None,
                                   'activation_event_id': None, 'message_ceiling': 95, 'event_ceiling': 201,
                                   'access_token': None}
        self.commands = []

    def close(self):
        self.temporary.cleanup()

    def runner(self, root, arguments, capture=False, timeout=40):
        self.commands.append(arguments)
        if self.hook:
            self.hook(self, arguments)
        if arguments[1:2] == ['-l']:
            return self.php(arguments)
        code = arguments[2]
        if code == helper.READ_CURRENT_PHP:
            if self.fail == 'readiness':
                return 1, b''
            pathlib.Path(arguments[3]).write_text(json.dumps(self.current))
            pathlib.Path(arguments[3]).chmod(0o600)
        elif code == helper.VALIDATE_PHP:
            if self.fail == 'validate':
                return 1, b''
        elif code == helper.CACHE_CANDIDATE_PHP:
            if self.fail == 'cache':
                return 1, b''
            return self.php(arguments)
        elif code == helper.SMOKE_PHP:
            if self.fail == 'smoke':
                return 1, b''
            configured = json.loads(pathlib.Path(arguments[3]).read_text())
            helper.validate_configuration(configured)
            orders, replies = configured['whatsapp_orders'], configured['whatsapp_replies']
            raw = (self.root / '.env').read_bytes()
            parsed = helper.parse_env(raw)[2]
            if parsed['WHATSAPP_ORDERS_API_KEY'][2] != (orders['api_key'] or ''):
                return 1, b''
            readiness = {'review_enabled': orders['enabled'], 'key_configured': orders['api_key'] is not None,
                         'model_configured': orders['model'] is not None,
                         'actor_configured': orders['automation_actor_id'] is not None,
                         'branches_configured': bool(orders['allowed_branch_ids']),
                         'historical_cutover_configured': orders['activation_message_id'] is not None and orders['activation_event_id'] is not None,
                         'automatic_dispatch_enabled': False, 'manual_replies_enabled': replies['enabled'],
                         'whatsapp_token_configured': replies['access_token'] is not None}
            return 0, json.dumps(readiness).encode()
        else:
            raise AssertionError('Unexpected operation')
        return 0, b''

    def php(self, arguments):
        if not PHP:
            raise unittest.SkipTest('PHP CLI required for real cache preservation tests')
        result = subprocess.run([PHP] + arguments[1:], cwd=self.root, capture_output=True, timeout=10)
        return result.returncode, result.stdout

    def configure(self):
        return helper.configure(self.root, os.geteuid(), runner=self.runner, prompt=feature)


class ConfigurationTests(unittest.TestCase):
    def fixture(self, **kwargs):
        fixture = Fixture(**kwargs)
        self.addCleanup(fixture.close)
        return fixture

    def test_hidden_key_and_review_defaults(self):
        tty, observed = FakeTty(['\n', '\n']), []

        def opener(path, mode, **kwargs):
            observed.append((path, mode))
            return tty

        def hidden(prompt, stream):
            self.assertIs(stream, tty)
            self.assertNotIn(KEY, prompt)
            return WA_TOKEN if 'WhatsApp access token' in prompt else KEY

        configured = helper.prompt_configuration({'enabled': False, 'message_ceiling': 95, 'event_ceiling': 201}, opener, hidden)
        self.assertEqual(observed, [('/dev/tty', 'r'), ('/dev/tty', 'w')])
        self.assertEqual(configured, feature())
        self.assertNotIn(KEY, ''.join(tty.prompts))

    def test_real_nonseekable_controlling_tty_hides_both_credentials(self):
        child, descriptor = pty.fork()
        if child == 0:
            try:
                configuration = helper.prompt_configuration({'enabled': False, 'message_ceiling': 95,
                    'event_ceiling': 201, 'api_key': None, 'access_token': None})
                print('REAL_TTY_PASS' if configuration == feature() else 'REAL_TTY_FAIL', flush=True)
                os._exit(0)
            except BaseException:
                print('REAL_TTY_FAIL', flush=True)
                os._exit(1)
        output, sent, finished = b'', set(), False
        deadline = time.monotonic() + 10
        replies = [(b'OpenAI API key (hidden; Enter preserves existing key): ', KEY + '\n'),
                   (b'WhatsApp access token (hidden; Enter preserves existing token): ', WA_TOKEN + '\n'),
                   (b'Model [gpt-5.6-terra]: ', '\n'),
                   (b'Automation actor ID (optional for human review; Enter leaves unset): ', '\n')]
        try:
            while time.monotonic() < deadline:
                ready, _, _ = select.select([descriptor], [], [], 0.1)
                if ready:
                    try:
                        chunk = os.read(descriptor, 4096)
                    except OSError as error:
                        if error.errno == errno.EIO:
                            finished = True
                            break
                        raise
                    if not chunk:
                        finished = True
                        break
                    output += chunk
                for index, (prompt, reply) in enumerate(replies):
                    if index not in sent and prompt in output:
                        os.write(descriptor, reply.encode())
                        sent.add(index)
            if not finished:
                os.kill(child, 9)
            _, status = os.waitpid(child, 0)
        finally:
            os.close(descriptor)
        self.assertTrue(finished and os.WIFEXITED(status) and os.WEXITSTATUS(status) == 0,
                        'Real controlling tty prompt failed')
        self.assertIn(b'REAL_TTY_PASS', output)
        self.assertNotIn(KEY.encode(), output)
        self.assertNotIn(WA_TOKEN.encode(), output)

    def test_key_preserved_without_printing_and_cutover_preserved(self):
        tty = FakeTty(['\n', '23\n', '7, 8,7\n'])
        current = {'enabled': True, 'activation_message_id': 12, 'activation_event_id': 33,
                   'message_ceiling': 95, 'event_ceiling': 201, 'api_key': KEY, 'access_token': WA_TOKEN}
        configured = helper.prompt_configuration(current, lambda *args, **kwargs: tty,
                                                 lambda *args, **kwargs: '')
        self.assertEqual(configured['whatsapp_orders']['api_key'], KEY)
        self.assertEqual(configured['whatsapp_replies']['access_token'], WA_TOKEN)
        self.assertEqual(configured['whatsapp_orders']['activation_message_id'], 12)
        self.assertEqual(configured['whatsapp_orders']['activation_event_id'], 33)
        self.assertEqual(configured['whatsapp_orders']['automation_actor_id'], '23')
        self.assertEqual(configured['whatsapp_orders']['allowed_branch_ids'], ['7', '8'])

    def test_manual_reply_can_be_enabled_without_ai_key_or_actor(self):
        tty = FakeTty([])
        configured = helper.prompt_configuration({'enabled': False, 'activation_message_id': None,
            'activation_event_id': None, 'api_key': None, 'access_token': None},
            lambda *args, **kwargs: tty,
            lambda prompt, **kwargs: WA_TOKEN if 'WhatsApp access token' in prompt else '')
        self.assertFalse(configured['whatsapp_orders']['enabled'])
        self.assertIsNone(configured['whatsapp_orders']['api_key'])
        self.assertIsNone(configured['whatsapp_orders']['automation_actor_id'])
        self.assertTrue(configured['whatsapp_replies']['enabled'])
        self.assertEqual(tty.prompts, [])

    def test_app_secret_whitespace_and_short_reply_tokens_are_rejected(self):
        for token in ['a' * 32, 'EAAshort', WA_TOKEN + '\nInjected', 'a' * 4097]:
            configured = feature()
            configured['whatsapp_replies']['access_token'] = token
            with self.subTest(token_length=len(token)), self.assertRaises(helper.SafeError):
                helper.validate_configuration(configured)

    def test_no_insecure_input_fallback(self):
        def unavailable(*args, **kwargs):
            raise OSError('secret-like-error-must-never-be-propagated')

        with self.assertRaisesRegex(helper.SafeError, '^SECURE_TERMINAL_INPUT_UNAVAILABLE$'):
            helper.prompt_configuration({}, unavailable)

        import warnings
        import getpass
        tty = FakeTty([])

        def warned(*args, **kwargs):
            warnings.warn('unavailable', getpass.GetPassWarning)
            return KEY

        with self.assertRaisesRegex(helper.SafeError, '^SECURE_TERMINAL_INPUT_UNAVAILABLE$'):
            helper.prompt_configuration({}, lambda *args, **kwargs: tty, warned)

    def test_env_preserves_comments_crlf_and_multiline_unrelated_values(self):
        original = (b'APP_NAME=Other\r\nOTHER="multiline\r\nWHATSAPP_ORDERS_MODEL=inside\r\nend"\r\n'
                    b' export WHATSAPP_ORDERS_MODEL = old-model  # keep comment\r\n'
                    b'WHATSAPP_ORDERS_ENABLED=false\r\n')
        proposed = helper.env_proposal(original, feature())
        self.assertIn(b'OTHER="multiline\r\nWHATSAPP_ORDERS_MODEL=inside\r\nend"\r\n', proposed)
        self.assertIn(b' export WHATSAPP_ORDERS_MODEL = "gpt-5.6-terra"  # keep comment\r\n', proposed)
        self.assertNotIn(b'\n', proposed.replace(b'\r\n', b''))
        self.assertEqual(helper.env_proposal(proposed, feature()), proposed)

    def test_env_duplicate_interpolation_or_malformed_quote_refused(self):
        for value in [b'WHATSAPP_ORDERS_MODEL=a\nWHATSAPP_ORDERS_MODEL=b\n',
                      b'WHATSAPP_ORDERS_API_KEY="${OTHER}"\n', b'OTHER="unterminated\n']:
            with self.subTest(value=value), self.assertRaises(helper.SafeError):
                helper.env_proposal(value, feature())

    def test_invalid_configuration_cannot_enable_auto_or_unset_boundary(self):
        for key, value in [('mode', 'auto'), ('activation_message_id', None),
                           ('activation_message_id', -1), ('automation_actor_id', '01'),
                           ('allowed_branch_ids', ['1']), ('model', 'model\nOTHER=bad'),
                           ('model', 'ft:model'), ('model', 'm' * 81)]:
            configured = feature()['whatsapp_orders']
            configured[key] = value
            with self.subTest(key=key), self.assertRaises(helper.SafeError):
                helper.validate_feature(configured)

    def test_no_cache_state_retained_private_backups_and_key_not_in_commands(self):
        fixture = self.fixture()
        status, backup, readiness = fixture.configure()
        self.assertEqual(status, 'WHATSAPP_INBOX_FEATURES_CONFIGURED')
        self.assertFalse((fixture.root / helper.CACHE_PATH).exists())
        self.assertFalse(readiness['automatic_dispatch_enabled'])
        self.assertEqual(stat.S_IMODE((fixture.root / '.env').stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)
        for path in backup.iterdir():
            if path.is_file():
                self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
        self.assertNotIn(KEY, repr(fixture.commands))
        self.assertNotIn(WA_TOKEN, repr(fixture.commands))
        self.assertNotIn('config:clear', repr(fixture.commands))
        self.assertNotIn('config:cache', repr(fixture.commands))
        self.assertNotIn('whatsapp:process-orders', repr(fixture.commands))

    def test_real_php_cache_retains_unrelated_effective_values(self):
        fixture = self.fixture(cache=True)
        fixture.configure()
        query = '$c=require $argv[1];echo json_encode($c);'
        result = subprocess.run([PHP, '-r', query, str(fixture.root / helper.CACHE_PATH)], capture_output=True, check=True)
        cached = json.loads(result.stdout)
        self.assertEqual(cached['app'], {'name': 'EffectiveLive', 'secret': 'opaque'})
        self.assertEqual(cached['whatsapp'], {'allowlist': ['468336579702269']})
        self.assertEqual(cached['whatsapp_orders'], feature()['whatsapp_orders'])
        self.assertEqual(cached['whatsapp_replies'], feature()['whatsapp_replies'])
        self.assertEqual(stat.S_IMODE((fixture.root / helper.CACHE_PATH).stat().st_mode), 0o600)

    def test_initial_cutover_refreshed_after_interactive_input(self):
        fixture = self.fixture()
        original_runner = fixture.runner
        reads = []

        def runner(root, arguments, **kwargs):
            if arguments[1:3] == ['-r', helper.READ_CURRENT_PHP]:
                reads.append(True)
                if len(reads) == 2:
                    fixture.current['message_ceiling'] = 101
                    fixture.current['event_ceiling'] = 209
            return original_runner(root, arguments, **kwargs)

        helper.configure(fixture.root, os.geteuid(), runner=runner, prompt=feature)
        parsed = helper.parse_env((fixture.root / '.env').read_bytes())[2]
        self.assertEqual(parsed['WHATSAPP_ORDERS_ACTIVATION_MESSAGE_ID'][2], '101')
        self.assertEqual(parsed['WHATSAPP_ORDERS_ACTIVATION_EVENT_ID'][2], '209')

    def test_php_handoffs_parse_without_executing_network_or_bootstrap(self):
        if not PHP:
            self.skipTest('PHP CLI required')
        fixture = self.fixture()
        for index, program in enumerate([helper.READ_CURRENT_PHP, helper.VALIDATE_PHP,
                                         helper.CACHE_CANDIDATE_PHP, helper.SMOKE_PHP]):
            source = fixture.home / ('lint-' + str(index) + '.php')
            source.write_text('<?php\n' + program)
            completed = subprocess.run([PHP, '-l', str(source)], capture_output=True)
            self.assertEqual(completed.returncode, 0, 'Fixed PHP handoff lint failed')

    def test_failed_smoke_restores_original_env_and_cache_inodes(self):
        fixture = self.fixture(cache=True, fail='smoke')
        with self.assertRaisesRegex(helper.SafeError, '^ORDERS_CONFIGURATION_RESTORED; BACKUP='):
            fixture.configure()
        self.assertEqual(helper.secure_snapshot(fixture.root / '.env', os.geteuid(), fixture.root), fixture.before_env)
        self.assertEqual(helper.secure_snapshot(fixture.root / helper.CACHE_PATH, os.geteuid(), fixture.root), fixture.before_cache)

    def test_readiness_validation_and_stage_failures_never_publish(self):
        for failed in ['readiness', 'validate', 'cache']:
            with self.subTest(failed=failed):
                fixture = self.fixture(cache=True, fail=failed)
                with self.assertRaises(helper.SafeError):
                    fixture.configure()
                self.assertEqual(helper.secure_snapshot(fixture.root / '.env', os.geteuid(), fixture.root), fixture.before_env)
                self.assertEqual(helper.secure_snapshot(fixture.root / helper.CACHE_PATH, os.geteuid(), fixture.root), fixture.before_cache)

    def test_symlink_and_hardlinked_env_refused(self):
        for link in ['symlink', 'hardlink']:
            with self.subTest(link=link):
                fixture = self.fixture()
                outside = fixture.home / 'secret-original'
                (fixture.root / '.env').rename(outside)
                if link == 'symlink':
                    (fixture.root / '.env').symlink_to(outside)
                else:
                    os.link(outside, fixture.root / '.env')
                with self.assertRaises(helper.SafeError):
                    fixture.configure()
                self.assertEqual(outside.read_bytes(), fixture.before_env.content)

    def test_prepublication_concurrent_env_edit_retained(self):
        def hook(fixture, arguments):
            if arguments[1:3] == ['-r', helper.VALIDATE_PHP]:
                (fixture.root / '.env').write_bytes(b'APP_NAME=Concurrent\n')

        fixture = self.fixture(hook=hook)
        with self.assertRaises(helper.SafeError):
            fixture.configure()
        self.assertEqual((fixture.root / '.env').read_bytes(), b'APP_NAME=Concurrent\n')

    def test_postpublication_concurrent_edit_not_overwritten_on_rollback(self):
        def hook(fixture, arguments):
            if arguments[1:3] == ['-r', helper.SMOKE_PHP]:
                (fixture.root / '.env').write_bytes(b'APP_NAME=ConcurrentAfterPublication\n')

        fixture = self.fixture(cache=True, hook=hook, fail='smoke')
        with self.assertRaisesRegex(helper.SafeError, 'ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):
            fixture.configure()
        self.assertEqual((fixture.root / '.env').read_bytes(), b'APP_NAME=ConcurrentAfterPublication\n')
        self.assertEqual(helper.secure_snapshot(fixture.root / helper.CACHE_PATH, os.geteuid(), fixture.root), fixture.before_cache)

    def test_atomic_exchange_race_preserves_competing_original(self):
        fixture = self.fixture()
        original_exchange = helper.base.atomic_exchange
        raced = []

        def exchange(source, destination):
            if destination == fixture.root / '.env' and not raced:
                raced.append(True)
                destination.write_bytes(b'APP_NAME=ConcurrentAtExchange\n')
            original_exchange(source, destination)

        helper.base.atomic_exchange = exchange
        self.addCleanup(setattr, helper.base, 'atomic_exchange', original_exchange)
        with self.assertRaisesRegex(helper.SafeError, 'ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):
            fixture.configure()
        self.assertEqual((fixture.root / '.env').read_bytes(), b'APP_NAME=ConcurrentAtExchange\n')
        for path in fixture.home.rglob('published-*'):
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)


if __name__ == '__main__':
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(ConfigurationTests)
    result = unittest.TextTestRunner(verbosity=1).run(suite)
    if result.wasSuccessful():
        print('WHATSAPP_ORDERS_SECURE_CONFIG_TESTS=' + str(result.testsRun))
    sys.exit(0 if result.wasSuccessful() else 1)
