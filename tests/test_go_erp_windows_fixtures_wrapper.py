"""Exercise completion protocol and failed-oneshot recovery without system changes."""
import json
from pathlib import Path
import re
import shlex
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
WRAPPER = ROOT / 'deployment/run_go_erp_windows_fixtures_complete_root.sh'
TEXT = WRAPPER.read_text()
PYTHON = re.findall(r"-c '\n(.*?)\n'", TEXT, re.S)
FUNCTIONS = TEXT[TEXT.index('go_worker() {'):TEXT.index('\ngo_action=\ngo_entry lease')]
LOOP = TEXT[TEXT.index('for ((go_round=0;'):TEXT.index('\ngo_entry verify')]


class WrapperTests(unittest.TestCase):
    def test_shell_and_every_embedded_python_program_parse(self):
        subprocess.run(['bash', '-n', str(WRAPPER)], check=True)
        self.assertEqual(len(PYTHON), 3)
        for program in PYTHON:
            compile(program, str(WRAPPER), 'exec')

    def protocol(self, mode, value):
        with tempfile.TemporaryDirectory() as directory:
            p = Path(directory) / 'result.json'
            p.write_text(json.dumps(value))
            return subprocess.run(['/usr/bin/python3', '-I', '-B', '-c', PYTHON[0], str(p), mode],
                capture_output=True, text=True)

    def response(self, action):
        return {'ok': True, 'kind': 'go-erp-windows-fixtures-completion',
            'worker_request': action, 'published': action == 'none',
            'publication_held': action != 'none', 'application_contents_changed': False,
            'database_changed': False, 'credentials_disclosed': False}

    def test_authorize_cannot_report_published_or_skip_publish(self):
        self.assertEqual(self.protocol('authorize', self.response('publish')).returncode, 0)
        self.assertNotEqual(self.protocol('authorize', self.response('none')).returncode, 0)
        value = self.response('publish')
        value['published'] = True
        self.assertNotEqual(self.protocol('authorize', value).returncode, 0)

    def test_status_rejects_wrong_kind_and_non_boolean_safety_flags(self):
        for key, bad in [('kind', 'go-erp-blue-sound-completion'),
                ('ok', 1), ('published', 0), ('publication_held', 1),
                ('database_changed', 0), ('credentials_disclosed', None),
                ('application_contents_changed', True)]:
            with self.subTest(key=key):
                value = self.response('collect')
                value[key] = bad
                self.assertNotEqual(self.protocol('status', value).returncode, 0)

    def test_verify_requires_final_published_response(self):
        self.assertEqual(self.protocol('verify', self.response('none')).returncode, 0)
        self.assertNotEqual(self.protocol('verify', self.response('collect')).returncode, 0)

    def shell(self, body, code='GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT',
            action='collect', state='failed', retry_at=0, extra=''):
        with tempfile.TemporaryDirectory() as directory:
            p = Path(directory)
            (p / 'result.json').write_text(json.dumps({'retry_at': retry_at}))
            (p / 'state.json').write_text('{"counter":42,"failures":1,"retry_at":123.5}\n')
            before = (p / 'state.json').read_bytes()
            script = r'''set -euo pipefail
go_stage=STAGE
go_service=go-erp-live-updates.service
go_action=collect
sleep() { printf 'SLEEP %s\n' "$1" >> "$go_stage/events"; }
journalctl() { printf '%s\n' 'private credential=never-display' CODE; }
systemctl() {
    if [[ "$1" = start ]]; then printf 'START\n' >> "$go_stage/events"; return 0; fi
    if [[ "$*" = *'--value'* ]]; then printf '10\n'; return 0; fi
    printf 'ActiveState=STATE\nExecMainStartTimestampMonotonic=20\nResult=exit-code\nExecMainStatus=1\n'
}
go_entry() { printf 'STATUS\n' >> "$go_stage/events"; go_action=ACTION; }
'''.replace('STAGE', shlex.quote(directory)).replace('CODE', shlex.quote(code))
            script = script.replace('STATE', state).replace('ACTION', action)
            script += FUNCTIONS + '\n' + extra + '\n' + body
            result = subprocess.run(['bash', '-c', script], capture_output=True, text=True)
            events = (p / 'events').read_text() if (p / 'events').exists() else ''
            self.assertEqual((p / 'state.json').read_bytes(), before)
            self.assertNotIn('credential=never-display', result.stdout + result.stderr)
            return result, events

    def test_generic_failure_rechecks_status_and_preserves_native_state(self):
        result, events = self.shell('go_collect\nprintf "RETRIES=%s\\n" "$go_transient_failures"')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(events.splitlines(), ['START', 'STATUS'])
        self.assertIn('RETRIES=1', result.stdout)

    def test_real_ci_failure_is_never_retried(self):
        result, events = self.shell('go_collect', code='GO_ERP_LIVE_CI_FAILED')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(events.splitlines(), ['START'])
        self.assertNotIn('TRANSIENT_COLLECT_RETRY', result.stdout)

    def test_generic_failures_stop_after_three_retries(self):
        result, events = self.shell('for i in 1 2 3 4; do go_collect; done')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(events.splitlines().count('START'), 4)
        self.assertEqual(events.splitlines().count('STATUS'), 3)
        self.assertEqual(result.stdout.count('TRANSIENT_COLLECT_RETRY'), 3)

    def test_changed_status_does_not_approve_retry(self):
        result, events = self.shell('go_collect', action='candidate')
        self.assertEqual(result.returncode, 78)
        self.assertEqual(events.splitlines(), ['START', 'STATUS'])
        self.assertNotIn('TRANSIENT_COLLECT_RETRY', result.stdout)

    def test_failed_status_validation_stops_retry(self):
        result, events = self.shell('go_collect', extra='go_entry() { return 79; }')
        self.assertEqual(result.returncode, 79)
        self.assertEqual(events.splitlines(), ['START'])

    def test_unbounded_backoff_does_not_start_worker(self):
        result, events = self.shell('go_collect', retry_at=time.time() + 7200)
        self.assertEqual(result.returncode, 75)
        self.assertEqual(events, '')

    def test_boolean_backoff_is_rejected(self):
        result, events = self.shell('go_collect', retry_at=True)
        self.assertEqual(result.returncode, 78)
        self.assertEqual(events, '')

    def test_unit_read_failure_cannot_start_worker(self):
        result, events = self.shell('go_collect', extra='systemctl() { return 81; }')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(events, '')

    def test_authorized_publication_respects_existing_backoff_on_both_routes(self):
        extra = r'''sleep() {
    printf 'BACKOFF\n' >> "$go_stage/events"
    printf '{"retry_at":0}\n' > "$go_stage/result.json"
}
go_worker() { printf 'WORKER %s\n' "$1" >> "$go_stage/events"; }
go_entry() {
    printf 'ENTRY %s\n' "$1" >> "$go_stage/events"
    if [[ "$1" = authorize ]]; then go_action=publish; else go_action=none; fi
}'''
        for action in ('authorize', 'publish'):
            with self.subTest(action=action):
                result, events = self.shell('go_action=' + action + '\n' + LOOP,
                    retry_at=time.time() + 120, extra=extra)
                self.assertEqual(result.returncode, 0, result.stderr)
                lines = events.splitlines()
                self.assertLess(lines.index('BACKOFF'), lines.index('WORKER publish'))
                self.assertEqual(lines.count('WORKER publish'), 1)

    def test_stale_success_does_not_count_as_new_invocation(self):
        extra = r'''systemctl() {
    if [[ "$1" = start ]]; then return 0; fi
    if [[ "$*" = *'--value'* ]]; then printf '10\n'; return 0; fi
    if [[ ! -f "$go_stage/seen" ]]; then
        touch "$go_stage/seen"; stamp=10
    else stamp=20; fi
    printf 'ActiveState=inactive\nExecMainStartTimestampMonotonic=%s\nResult=success\nExecMainStatus=0\n' "$stamp"
}'''
        result, events = self.shell('go_worker candidate', extra=extra)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(events.splitlines(), ['SLEEP 10'])
        self.assertIn('GO_ERP_WAITING_WORKER', result.stdout)


if __name__ == '__main__':
    unittest.main()
