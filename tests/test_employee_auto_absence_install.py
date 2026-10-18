#!/usr/bin/env python3
"""Isolated source/activation/crontab fixtures; never touch a real user's cron or database."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('auto_absence_installer', ROOT/'deployment/apply_employee_auto_absence.py')
installer = importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(installer)


class Fixture:
    def __init__(self, folder):
        self.root=Path(folder)/'app';self.root.mkdir();self.calls=[];self.cron_writes=[]
        self.cron={'present':True,'body':b'MAILTO="owner@example.invalid"\n17 3 * * * /private/existing-job\n'}
        self.before_cron=dict(self.cron);self.fail_lint=False;self.fail_dry_run=False;self.fail_schema=False
        self.during_dry_run=None;self.during_write=None;self.fail_write_once=False;self.real_run=installer.run
        self.git('init','-q');self.git('config','user.name','Fixture');self.git('config','user.email','fixture@example.invalid')
        (self.root/'README').write_text('existing application\n');self.git('add','.');self.git('commit','-qm','base')
        self.base=self.git('rev-parse','HEAD').strip();self.sources={}
        for path in installer.SOURCE_PATHS:
            self.sources[path]=b'<?php // reviewed new service\n';self.write(path,self.sources[path])
        self.git('add','.');self.git('commit','-qm','release');self.release=self.git('rev-parse','HEAD').strip()
        self.git('reset','--hard',self.base)
        for path in installer.SOURCE_PATHS:(self.root/path).parent.mkdir(parents=True,exist_ok=True)

    @property
    def private(self):return self.root.parent/'employee-auto-absence-backups'
    @property
    def activation(self):return self.private/installer.ACTIVATION_NAME
    def git(self,*args):
        return subprocess.check_output(['git','-C',str(self.root),*args],stderr=subprocess.PIPE).decode()
    def write(self,path,body):
        target=self.root/path;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(body)
    def schema(self,root,php):
        if self.fail_schema:raise RuntimeError('EMPLOYEE_AUTO_ABSENCE_SCHEMA_NEEDS_REVIEW')
        return {'today':'2026-10-18'}
    def lint(self,php,directory):
        for path in installer.SOURCE_PATHS:assert (directory/path).is_file()
        if self.fail_lint:raise RuntimeError('PHP_PARSE_INVALID')
    def run(self,command,cwd=None):
        if command[:3]==['fixture-php','artisan','employees:auto-absence']:
            self.calls.append(tuple(command))
            assert command[3:]==['--dry-run','--no-interaction','--no-ansi']
            assert self.activation.is_file()
            if self.during_dry_run:self.during_dry_run()
            return subprocess.CompletedProcess(command,1 if self.fail_dry_run else 0,stdout=b'Employee automatic absence: {"ready":true,"considered":2,"recorded":0,"skipped":2,"errors":0}\n',stderr=b'')
        return self.real_run(command,cwd)
    def cron_state(self):return dict(self.cron)
    def write_cron(self,state):
        self.cron_writes.append(dict(state));self.cron=dict(state)
        if self.during_write:self.during_write()
        if self.fail_write_once:
            self.fail_write_once=False;raise RuntimeError('EMPLOYEE_AUTO_ABSENCE_CRON_WRITE_FAILED')
    def apply(self,check=False):
        with (mock.patch.object(installer,'BASE_REVISION',self.base),mock.patch.object(installer,'run',self.run),
              mock.patch.object(installer,'schema_state',self.schema),mock.patch.object(installer,'lint_sources',self.lint),
              mock.patch.object(installer,'cron_state',self.cron_state),mock.patch.object(installer,'write_cron',self.write_cron),
              contextlib.redirect_stdout(io.StringIO())):
            return installer.install(self.root,self.release,'fixture-php',check)
    def assert_untouched(self,case):
        for path in installer.SOURCE_PATHS:case.assertFalse((self.root/path).exists())
        case.assertFalse(self.activation.exists());case.assertEqual(self.cron,self.before_cron)
    def receipt(self):return json.loads(next(self.private.glob('*/receipt.json')).read_text())
    def marker(self,body=b'{"starts_on":"2026-10-17"}\n'):
        self.private.mkdir(mode=0o700,exist_ok=True);self.activation.write_bytes(body);return body


class AutoAbsenceInstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory();self.fixture=Fixture(self.temporary.name);self.old_umask=os.umask(0o077)
    def tearDown(self):os.umask(self.old_umask);self.temporary.cleanup()

    def test_check_only_publishes_no_sources_marker_or_cron(self):
        self.assertIsNone(self.fixture.apply(True));self.fixture.assert_untouched(self)
        self.assertEqual(self.fixture.calls,[]);self.assertEqual(self.fixture.cron_writes,[])
        self.assertEqual([p.name for p in self.fixture.private.iterdir()],['install.lock'])

    def test_install_preserves_existing_cron_and_records_private_backup(self):
        backup=self.fixture.apply()
        for path,body in self.fixture.sources.items():
            self.assertEqual((self.fixture.root/path).read_bytes(),body)
            self.assertEqual(stat.S_IMODE((self.fixture.root/path).stat().st_mode),0o644)
        self.assertEqual(json.loads(self.fixture.activation.read_bytes()),{'starts_on':'2026-10-18'})
        self.assertEqual(self.fixture.cron['body'],self.fixture.before_cron['body']+installer.CRON_LINE+b'\n')
        self.assertEqual((backup/'crontab.before').read_bytes(),self.fixture.before_cron['body'])
        self.assertEqual(stat.S_IMODE((backup/'crontab.before').stat().st_mode),0o600)
        self.assertEqual(self.fixture.receipt()['status'],'installed')
        self.assertFalse(self.fixture.receipt()['database_changes_attempted'])
        self.assertEqual(len(self.fixture.calls),1)

    def test_rerun_does_not_add_cron_or_repeat_dry_run(self):
        self.fixture.apply();before=self.fixture.cron['body'];calls=list(self.fixture.calls)
        self.assertIsNone(self.fixture.apply());self.assertEqual(self.fixture.cron['body'],before)
        self.assertEqual(self.fixture.calls,calls);self.assertEqual(len(self.fixture.cron_writes),1)

    def test_new_source_collision_stops_before_dry_run_or_cron(self):
        path=installer.SOURCE_PATHS[0];self.fixture.write(path,b'custom service\n')
        with self.assertRaisesRegex(RuntimeError,'NEW_SOURCE_COLLISION_NEEDS_REVIEW'):self.fixture.apply()
        self.assertEqual((self.fixture.root/path).read_bytes(),b'custom service\n')
        self.assertEqual(self.fixture.calls,[]);self.assertEqual(self.fixture.cron_writes,[])

    def test_schema_failure_leaves_sources_marker_and_cron_untouched(self):
        self.fixture.fail_schema=True
        with self.assertRaisesRegex(RuntimeError,'SCHEMA_NEEDS_REVIEW'):self.fixture.apply()
        self.fixture.assert_untouched(self)

    def test_stage_php_parse_failure_leaves_live_sources_untouched(self):
        self.fixture.fail_lint=True
        with self.assertRaisesRegex(RuntimeError,'PHP_PARSE_INVALID'):self.fixture.apply()
        self.fixture.assert_untouched(self)

    def test_unregistered_or_failed_dry_run_restores_new_sources_and_marker(self):
        self.fixture.fail_dry_run=True
        with self.assertRaisesRegex(RuntimeError,'DRY_RUN_FAILED'):self.fixture.apply()
        self.fixture.assert_untouched(self);self.assertEqual(self.fixture.receipt()['status'],'restored')
        self.assertEqual(self.fixture.cron_writes,[])

    def test_existing_marker_is_preserved_exactly_when_install_fails(self):
        marker=self.fixture.marker();self.fixture.fail_dry_run=True
        with self.assertRaisesRegex(RuntimeError,'DRY_RUN_FAILED'):self.fixture.apply()
        self.assertEqual(self.fixture.activation.read_bytes(),marker)
        for path in installer.SOURCE_PATHS:self.assertFalse((self.fixture.root/path).exists())

    def test_unknown_existing_marker_is_rejected(self):
        for marker in [b'{}',b'{"starts_on":"2026-02-30"}',b'{"starts_on":"2026-10-19"}',b'{"starts_on":"2026-10-18","extra":1}']:
            with self.subTest(marker=marker):
                self.fixture.marker(marker)
                with self.assertRaisesRegex(RuntimeError,'ACTIVATION_NEEDS_REVIEW'):self.fixture.apply()
                self.assertEqual(self.fixture.activation.read_bytes(),marker)
                self.assertEqual(self.fixture.calls,[])

    def test_cron_change_before_write_is_preserved_and_sources_restored(self):
        changed=self.fixture.before_cron['body']+b'9 4 * * * /private/concurrent-job\n'
        self.fixture.during_dry_run=lambda:self.fixture.cron.update(body=changed)
        with self.assertRaisesRegex(RuntimeError,'CRON_CHANGED_BEFORE_WRITE'):self.fixture.apply()
        self.assertEqual(self.fixture.cron['body'],changed);self.assertEqual(self.fixture.cron_writes,[])
        self.assertFalse(self.fixture.activation.exists())
        for path in installer.SOURCE_PATHS:self.assertFalse((self.fixture.root/path).exists())

    def test_failed_cron_write_restores_exact_previous_cron(self):
        self.fixture.fail_write_once=True
        with self.assertRaisesRegex(RuntimeError,'CRON_WRITE_FAILED'):self.fixture.apply()
        self.fixture.assert_untouched(self);self.assertEqual(len(self.fixture.cron_writes),2)
        self.assertEqual(self.fixture.receipt()['status'],'restored')

    def test_concurrent_cron_edit_after_write_is_not_overwritten_on_rollback(self):
        def change():self.fixture.cron['body']+=b'9 4 * * * /private/concurrent-job\n'
        self.fixture.during_write=change
        with self.assertRaisesRegex(RuntimeError,'CRON_POSTCHECK_FAILED'):self.fixture.apply()
        self.assertIn(b'/private/concurrent-job',self.fixture.cron['body'])
        self.assertEqual(self.fixture.receipt()['status'],'rollback_review_required')
        self.assertIn('crontab',self.fixture.receipt()['review'])
        self.assertEqual(len(self.fixture.cron_writes),1)

    def test_source_concurrent_edit_is_retained_for_review(self):
        path=installer.SOURCE_PATHS[0]
        self.fixture.during_dry_run=lambda:self.fixture.write(path,b'concurrent source\n')
        with self.assertRaisesRegex(RuntimeError,'LIVE_FILE_CHANGED_DURING_INSTALL'):self.fixture.apply()
        self.assertEqual((self.fixture.root/path).read_bytes(),b'concurrent source\n')
        self.assertIn(path,self.fixture.receipt()['review']);self.assertEqual(self.fixture.cron_writes,[])

    def test_symlink_source_and_activation_are_rejected(self):
        target=self.fixture.root/installer.SOURCE_PATHS[0];outside=self.fixture.root/'outside.php';outside.write_bytes(b'x')
        target.symlink_to(outside)
        with self.assertRaisesRegex(RuntimeError,'SYMLINK_REJECTED'):self.fixture.apply(True)
        target.unlink();self.fixture.marker();saved=self.fixture.private/'outside.json';self.fixture.activation.rename(saved)
        self.fixture.activation.symlink_to(saved)
        with self.assertRaisesRegex(RuntimeError,'SYMLINK_REJECTED'):self.fixture.apply(True)

    def test_hardlinked_source_is_rejected(self):
        path=installer.SOURCE_PATHS[0];self.fixture.write(path,b'x');os.link(self.fixture.root/path,self.fixture.root/'outside.php')
        with self.assertRaisesRegex(RuntimeError,'REGULAR_UNLINKED_SOURCE_REQUIRED'):self.fixture.apply(True)

    def test_absent_previous_crontab_is_restored_as_absent(self):
        self.fixture.cron=self.fixture.before_cron={'present':False,'body':b''};self.fixture.fail_write_once=True
        with self.assertRaisesRegex(RuntimeError,'CRON_WRITE_FAILED'):self.fixture.apply()
        self.assertEqual(self.fixture.cron,{'present':False,'body':b''})
        self.assertFalse(self.fixture.cron_writes[-1]['present'])

    def test_manifest_contains_only_two_new_php_files_and_no_kernel_or_migration(self):
        self.assertEqual(len(installer.SOURCE_PATHS),2)
        self.assertFalse(any('Kernel' in path or 'migration' in path for path in installer.SOURCE_PATHS))
        wrapper=(ROOT/'deployment/apply_employee_auto_absence_root.sh').read_text()
        self.assertIn(installer.BASE_REVISION,wrapper);self.assertIn('runuser -u fasakha -- python3',wrapper)
        self.assertIn('employees:auto-absence',installer.CRON_LINE.decode())


class CronAndSchemaContractTests(unittest.TestCase):
    def test_related_unknown_or_duplicate_cron_entries_are_rejected(self):
        for body in [installer.CRON_LINE+b'\n'+installer.CRON_LINE+b'\n',
                     b'* * * * * other-artisan employees:auto-absence\n',
                     b'# changed '+installer.CRON_MARKER.encode()+b'\n']:
            with self.subTest(body=body),self.assertRaisesRegex(RuntimeError,'CRON_NEEDS_REVIEW'):
                installer.cron_candidate({'present':True,'body':body})

    def test_existing_exact_cron_line_is_idempotent(self):
        state={'present':True,'body':b'CRON_TZ=Africa/Cairo\n'+installer.CRON_LINE+b'\n'}
        self.assertEqual(installer.cron_candidate(state),state)

    def test_existing_non_utf8_cron_bytes_and_missing_final_newline_are_preserved(self):
        before=b'# private-byte \xff\n4 5 * * * /private/job'
        result=installer.cron_candidate({'present':True,'body':before})
        self.assertEqual(result['body'],before+b'\n'+installer.CRON_LINE+b'\n')

    def test_cron_read_accepts_only_real_absence_and_hides_other_errors(self):
        with mock.patch.object(installer,'run',return_value=subprocess.CompletedProcess([],1,stdout=b'',stderr=b'no crontab for fasakha\n')):
            self.assertEqual(installer.cron_state(),{'present':False,'body':b''})
        with mock.patch.object(installer,'run',return_value=subprocess.CompletedProcess([],1,stdout=b'',stderr=b'PRIVATE_PERMISSION_FAILURE')):
            with self.assertRaisesRegex(RuntimeError,'^EMPLOYEE_AUTO_ABSENCE_CRON_UNREADABLE$'):installer.cron_state()

    def test_dry_run_reply_requires_the_registered_command_counts(self):
        for body in [b'',b'Another command succeeded',b'Employee automatic absence: {}',
                     b'Employee automatic absence: {"ready":true,"considered":1,"recorded":1,"skipped":0,"errors":1}']:
            with self.subTest(body=body),self.assertRaisesRegex(RuntimeError,'^EMPLOYEE_AUTO_ABSENCE_DRY_RUN_FAILED$'):
                installer.verify_dry_run(subprocess.CompletedProcess([],0,stdout=body,stderr=b'PRIVATE_DETAILS'))

    def test_schema_contract_rejects_missing_malformed_or_invalid_dates(self):
        for body in [b'PRIVATE_SQL_DETAIL',b'{}',b'{"today":1}',b'{"today":"2026-02-30"}',b'{"today":"2026-10-18","extra":true}']:
            with self.subTest(body=body),mock.patch.object(installer,'run',return_value=subprocess.CompletedProcess([],0,stdout=body,stderr=b'PRIVATE_DETAIL')):
                with self.assertRaisesRegex(RuntimeError,'^EMPLOYEE_AUTO_ABSENCE_SCHEMA_NEEDS_REVIEW$'):installer.schema_state(ROOT,'fixture-php')


if __name__=='__main__':unittest.main()
