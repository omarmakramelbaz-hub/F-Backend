"""Only the separately pinned and fully accepted web source may enter capture."""
import copy
import hashlib
import json
import types
import unittest
from unittest.mock import patch

from test_go_erp_navigation_rollout import subject


class WebSourceCIGuards(unittest.TestCase):
    def setUp(self):
        self.release = '4' * 40
        self.branch = 'codex/go-erp-navigation-web-test'
        self.workflow = 'go-erp-navigation-web-tests.yml'
        self.workflow_raw = b'name: immutable reviewed web checks\n'
        self.jobs = ('Original Laravel', 'Browser navigation', 'Owner money parity')
        self.payloads = {name: ('reviewed web source ' + name).encode() for name in subject.WEB_PATCHES}
        self.before = {name: ('accepted old source ' + name).encode() for name in subject.WEB_PATCHES}
        self.pins = {name: {'before': subject.sha(self.before[name]), 'after': subject.sha(self.payloads[name])} for name in self.payloads}
        self.run = {'id': 123, 'run_attempt': 2, 'event': 'push', 'head_branch': self.branch,
            'head_sha': self.release, 'pull_requests': [], 'path': '.github/workflows/' + self.workflow,
            'status': 'completed', 'conclusion': 'success',
            'repository': {'id': 1311412084, 'full_name': 'omarmakramelbaz-hub/F-Backend'},
            'head_repository': {'id': 1311412084, 'full_name': 'omarmakramelbaz-hub/F-Backend'}}
        self.rows = [copy.deepcopy(self.run)]
        self.job_rows = [{'id': i + 500, 'name': name, 'status': 'completed', 'conclusion': 'success',
            'run_id': 123, 'run_attempt': 2, 'head_sha': self.release} for i, name in enumerate(self.jobs)]
        self.calls = []
        self.paths = sorted(self.payloads)
        outer = self
        class Client:
            def pages(self, endpoint, key):
                outer.calls.append((endpoint, key))
                return outer.rows if key == 'workflow_runs' else outer.job_rows
        def file(name, revision=None):
            if revision == outer.release and name == '.github/workflows/' + outer.workflow: return outer.workflow_raw
            if revision == outer.release: return outer.payloads[name]
            if revision in (subject.OLD_CORE, subject.INPUT_CANDIDATE): return outer.before[name]
            raise AssertionError('Unexpected source revision')
        config = {'base_revision': subject.OLD_SEED, 'token': 'PRIVATE_TEST_TOKEN'}
        self.ctx = types.SimpleNamespace(release=types.SimpleNamespace(file=file,
            git=lambda *args: ('\n'.join(self.paths) + '\n').encode()),
            state=lambda: {'base_revision': subject.OLD_SEED},
            base=types.SimpleNamespace(parse=json.loads, checked_config=lambda value, seed: self.assertEqual(value['base_revision'], seed)),
            e=types.SimpleNamespace(read_file=lambda *args, **kwargs: (json.dumps(config).encode(), None)),
            worker=types.SimpleNamespace(GitHub=lambda token: Client()))
        self.template = types.SimpleNamespace(CONFIG='/private/config')
        self.patch = patch.multiple(subject, WEB_PINS_PENDING=False, WEB_RELEASE=self.release,
            WEB_BRANCH=self.branch, WEB_WORKFLOW=self.workflow, WEB_WORKFLOW_SHA256=subject.sha(self.workflow_raw),
            WEB_RUN_ID=123, WEB_JOBS=self.jobs, WEB_PATCHES=self.pins)
        self.patch.start(); self.addCleanup(self.patch.stop)

    def test_exact_latest_attempt_all_jobs_and_all_three_source_bytes_are_required(self):
        payloads, proof = subject.verify_web_ci(self.ctx, self.template)
        self.assertEqual(payloads, self.payloads)
        self.assertEqual(proof['release'], self.release)
        self.assertEqual(proof['run_id'], 123); self.assertEqual(proof['run_attempt'], 2)
        self.assertEqual(proof['jobs'], {name: i + 500 for i, name in enumerate(self.jobs)})
        self.assertIn('/attempts/2/jobs', self.calls[-1][0])
        self.assertEqual(len(self.calls), 2)

    def test_unreviewed_extra_application_path_stops_before_network(self):
        self.paths.append('routes/admin.php')
        with self.assertRaisesRegex(subject.Stop, 'WEB_SOURCE_SCOPE_CHANGED'):
            subject.verify_web_ci(self.ctx, self.template)
        self.assertEqual(self.calls, [])

    def test_workflow_or_payload_change_stops_before_network(self):
        self.workflow_raw += b'# changed\n'
        with self.assertRaisesRegex(subject.Stop, 'WEB_CI_WORKFLOW_PIN_CHANGED'):
            subject.verify_web_ci(self.ctx, self.template)
        self.assertEqual(self.calls, [])
        self.workflow_raw = b'name: immutable reviewed web checks\n'
        name = sorted(self.payloads)[0]; self.payloads[name] += b' changed'
        with self.assertRaisesRegex(subject.Stop, 'WEB_SOURCE_PAYLOAD_PIN_CHANGED'):
            subject.verify_web_ci(self.ctx, self.template)
        self.assertEqual(self.calls, [])

    def test_later_run_cannot_be_ignored_in_favor_of_a_pinned_older_success(self):
        later = copy.deepcopy(self.run); later.update(id=124, conclusion='failure')
        self.rows.append(later)
        with self.assertRaisesRegex(subject.Stop, 'WEB_CI_RUN_IDENTITY_INVALID'):
            subject.verify_web_ci(self.ctx, self.template)
        self.assertEqual(len(self.calls), 1)

    def test_wrong_repository_or_source_or_push_identity_fails_closed(self):
        for field, value in [('repository', {'id': 1, 'full_name': 'elsewhere'}),
            ('head_repository', {'id': 1, 'full_name': 'elsewhere'}), ('run_attempt', True),
            ('path', '.github/workflows/not-reviewed.yml')]:
            self.rows = [copy.deepcopy(self.run)]; self.rows[0][field] = value
            with self.subTest(field=field), self.assertRaisesRegex(subject.Stop, 'WEB_CI_RUN_IDENTITY_INVALID'):
                subject.verify_web_ci(self.ctx, self.template)
        for field, value in [('head_sha', '5' * 40), ('event', 'pull_request'), ('head_branch', 'elsewhere')]:
            self.rows = [copy.deepcopy(self.run)]; self.rows[0][field] = value
            with self.subTest(field=field), self.assertRaisesRegex(subject.Stop, 'WEB_CI_SUCCESS_REQUIRED'):
                subject.verify_web_ci(self.ctx, self.template)

    def test_incomplete_or_inherited_wrong_attempt_job_is_not_accepted(self):
        original = copy.deepcopy(self.job_rows)
        for mode in ('missing', 'duplicate', 'failed', 'previous-attempt', 'other-source'):
            self.job_rows = copy.deepcopy(original)
            if mode == 'missing': self.job_rows.pop()
            elif mode == 'duplicate': self.job_rows.append(copy.deepcopy(self.job_rows[0]))
            elif mode == 'failed': self.job_rows[0]['conclusion'] = 'failure'
            elif mode == 'previous-attempt': self.job_rows[0]['run_attempt'] = 1
            else: self.job_rows[0]['head_sha'] = '5' * 40
            with self.subTest(mode=mode), self.assertRaisesRegex(subject.Stop, 'WEB_CI_JOB_ACCEPTANCE_INCOMPLETE'):
                subject.verify_web_ci(self.ctx, self.template)

    def test_pending_web_pins_never_read_source_or_contact_github(self):
        with patch.object(subject, 'WEB_PINS_PENDING', True):
            with self.assertRaisesRegex(subject.Stop, 'WEB_PINS_PENDING'):
                subject.verify_web_ci(self.ctx, self.template)
        self.assertEqual(self.calls, [])

    def test_missing_reload_policy_stops_before_audit_or_source_module(self):
        from test_go_erp_navigation_rollout import published_state
        state = published_state(); events = []
        ctx = types.SimpleNamespace(held_verify=lambda: events.append('lock'), state=lambda: state,
            publication=lambda: state['published']['release'], quiet_hold=lambda: events.append('quiet-hold'),
            previous_proof=lambda: events.append('predecessor'))
        with patch.object(subject, 'checked_existing_manual', side_effect=lambda *args: events.append('manual')), \
                patch.object(subject, 'verify_web_ci', return_value=({}, {'reviewed': True})), \
                patch.object(subject, 'verify_php_handler', side_effect=subject.Stop('PHP_HANDLER_REVIEW_REQUIRED')):
            with self.assertRaisesRegex(subject.Stop, 'PHP_HANDLER_REVIEW_REQUIRED'):
                subject.apply_web_source(ctx, types.SimpleNamespace())
        self.assertEqual(events, ['lock', 'quiet-hold', 'predecessor', 'manual'])

    def test_unknown_hold_or_receipt_stops_before_web_ci_or_source_changes(self):
        from test_go_erp_navigation_rollout import published_state
        state = published_state()
        def quiet_hold():
            raise subject.Stop('PREVIOUS_ROLLOUT_HOLD_PRESENT')
        ctx = types.SimpleNamespace(held_verify=lambda: None, state=lambda: state,
            publication=lambda: state['published']['release'], quiet_hold=quiet_hold)
        with patch.object(subject, 'verify_web_ci', side_effect=AssertionError('CI must not run')):
            with self.assertRaisesRegex(subject.Stop, 'PREVIOUS_ROLLOUT_HOLD_PRESENT'):
                subject.apply_web_source(ctx, types.SimpleNamespace())

    def test_changed_previous_publication_stops_before_ci_or_source_changes(self):
        from test_go_erp_navigation_rollout import published_state
        state = published_state(); state['counter'] = 59
        ctx = types.SimpleNamespace(held_verify=lambda: None, state=lambda: state,
            publication=lambda: state['published']['release'])
        with patch.object(subject, 'verify_web_ci', side_effect=AssertionError('CI must not run')):
            with self.assertRaisesRegex(subject.Stop, 'EXACT_PUBLISHED_NAVIGATION_INPUT_REQUIRED'):
                subject.apply_web_source(ctx, types.SimpleNamespace())

    def test_reload_rechecks_the_bound_configuration_before_and_after_graceful_reload(self):
        calls = []
        result = {'configuration':{'domain':'reviewed'},'live':{'master_pid':1}}
        def verify(engine):
            calls.append('verify')
            return copy.deepcopy(result)
        ctx = types.SimpleNamespace(_php_attestor=types.SimpleNamespace(verify=verify),
            e=types.SimpleNamespace(run=lambda args: calls.append(args)))
        subject.reload_php_handler(ctx)
        self.assertEqual(calls, ['verify', ['/usr/bin/systemctl', 'reload', 'ea-php82-php-fpm.service'], 'verify'])
        result['configuration']['domain'] = 'concurrent-change'
        with self.assertRaisesRegex(subject.Stop, 'PHP_HANDLER_CONFIGURATION_CHANGED'):
            subject.reload_php_handler(ctx)


if __name__ == '__main__':
    unittest.main()
