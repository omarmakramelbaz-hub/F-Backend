"""Pinned single-migration orchestration; never starts a service or reads account rows."""
import importlib.util
import fcntl
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import types
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('employee_migration_test',ROOT/'deployment/go_erp_employee_migration.py')
M = importlib.util.module_from_spec(spec); spec.loader.exec_module(M)


def schema(columns=0, ledger=0):
    return {'format':1,'kind':'go-erp-employee-schema','ok':True,'column_count':columns,
        'complete':columns==9,'ledger':ledger,'signature':str(columns)*63+str(ledger),'business_rows_read':False}


class Runner:
    def __init__(self, before=None, after=None, failure=None, status=0):
        self.before=before or schema(); self.after=after or schema(9,1)
        self.failure=failure; self.status=status; self.commands=[]; self.probes=0; self.unit='inactive'
    def __call__(self,args,**options):
        self.commands.append((args,options))
        if args[0]=='/usr/bin/systemctl':
            return types.SimpleNamespace(returncode=0,stdout=(self.unit+'\n').encode())
        if 'migrate' in args:
            os.write(options['stdout'],b'PRIVATE framework detail: never public\n')
            if self.failure: raise self.failure
            return types.SimpleNamespace(returncode=self.status)
        self.probes+=1
        self_value=self.before if self.probes==1 else self.after
        os.write(options['stdout'],json.dumps(self_value).encode())
        return types.SimpleNamespace(returncode=0)


@unittest.skipUnless(os.geteuid()==0,'root-owned deployment permissions')
class MigrationTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='employee-migration-proof-')
        self.base=Path(self.temp.name); self.root=self.base/'live'; self.work=self.base/'work'
        self.package=self.base/'package'; self.audit=self.base/'audit'
        for path in [self.root,self.work,self.package,self.audit]: path.mkdir(mode=0o700)
        migration=self.root/M.MIGRATION; migration.parent.mkdir(parents=True)
        self.migration=b'<?php // reviewed nullable schema additions only\n'; migration.write_bytes(self.migration); migration.chmod(0o644)
        self.probe=b'<?php // metadata-only fixture\n'; (self.package/M.PROBE).write_bytes(self.probe)
        self.lock=os.open(self.work/'watcher.lock',os.O_RDONLY|os.O_CREAT|os.O_EXCL,0o600)
        self.patches=[mock.patch.object(M,'ROOT',self.root),mock.patch.object(M,'WORK',self.work),
            mock.patch.object(M.socket,'gethostname',return_value='srv1836524'),
            mock.patch.object(M.pwd,'getpwnam',return_value=types.SimpleNamespace(pw_uid=0,pw_gid=0))]
        for patch in self.patches: patch.start()
    def tearDown(self):
        for patch in reversed(self.patches): patch.stop()
        os.close(self.lock); self.temp.cleanup()
    def run_migration(self,runner,apply=True,**changes):
        args=dict(package=self.package,audit_parent=self.audit,migration_blob=M.blob(self.migration),
            probe_blob=M.blob(self.probe),apply=apply,lock_fd=self.lock,runner=runner)
        args.update(changes); return M.execute(**args)
    def assert_private(self,result):
        for secret in ['PRIVATE framework detail','PRIVATE command','PRIVATE after schema change']:
            self.assertNotIn(secret,json.dumps(result))
        folder=Path(result['audit_directory'])
        self.assertEqual(stat.S_IMODE(folder.stat().st_mode),0o700)
        for path in folder.iterdir(): self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o600)
        self.assertFalse(result['watcher_started']); self.assertFalse(result['published']); self.assertFalse(result['schema_rollback_attempted'])
    def test_exact_path_user_php_and_private_metadata_probe(self):
        runner=Runner(); result=self.run_migration(runner)
        self.assertTrue(result['ok']); self.assertIs(result['database_changed'],True); self.assertTrue(result['migration_attempted'])
        artisan=[(a,o) for a,o in runner.commands if 'migrate' in a]
        self.assertEqual(len(artisan),1)
        args,options=artisan[0]
        self.assertEqual(args,[M.RUNUSER,'-u','fasakha','--',M.PHP,'-d','display_errors=0','-d','log_errors=0',
            str(self.root/'artisan'),'migrate','--force','--no-interaction','--path='+M.MIGRATION])
        self.assertEqual(options['cwd'],str(self.root)); self.assertNotIn('shell',options)
        probes=[(a,o) for a,o in runner.commands if a[0]!= '/usr/bin/systemctl' and 'migrate' not in a]
        self.assertEqual(len(probes),2)
        for args,options in probes:
            self.assertEqual(options['input'],self.probe); self.assertNotIn(str(self.package/M.PROBE),args)
        self.assert_private(result)
        self.assertTrue(any(b'PRIVATE' in p.read_bytes() for p in Path(result['audit_directory']).iterdir()))
    def test_check_and_already_current_never_invoke_artisan(self):
        for apply,before in [(False,schema(3,0)),(True,schema(9,1))]:
            runner=Runner(before=before); result=self.run_migration(runner,apply)
            self.assertTrue(result['ok']); self.assertIs(result['database_changed'],False); self.assertFalse(result['migration_attempted'])
            self.assertEqual(runner.probes,1); self.assertFalse(any('migrate' in args for args,_ in runner.commands)); self.assert_private(result)
    def test_partial_additions_are_completed_by_only_the_reviewed_path(self):
        runner=Runner(before=schema(3,0)); result=self.run_migration(runner)
        self.assertTrue(result['ok']); self.assertEqual(result['column_count'],9); self.assertIs(result['database_changed'],True)
    def test_unrecorded_complete_schema_still_records_migration(self):
        result=self.run_migration(Runner(before=schema(9,0)))
        self.assertTrue(result['ok']); self.assertIs(result['database_changed'],True); self.assertTrue(result['migration_recorded'])
    def test_failed_artisan_with_observed_additions_reports_true_without_rollback(self):
        result=self.run_migration(Runner(after=schema(4,0),status=1))
        self.assertFalse(result['ok']); self.assertIs(result['database_changed'],True)
        self.assertEqual(result['reason'],'GO_ERP_EMPLOYEE_MIGRATION_MIGRATION_NOT_VERIFIED'); self.assert_private(result)
    def test_timeout_after_attempt_keeps_unknown_and_private_output(self):
        result=self.run_migration(Runner(failure=subprocess.TimeoutExpired('PRIVATE command',180)))
        self.assertFalse(result['ok']); self.assertEqual(result['database_changed'],'unknown'); self.assertTrue(result['migration_attempted'])
        self.assertEqual(result['reason'],'GO_ERP_EMPLOYEE_MIGRATION_PRIVATE_FAILURE'); self.assert_private(result)
    def test_missing_postcondition_is_never_success(self):
        for after in [schema(8,0),schema(9,0)]:
            result=self.run_migration(Runner(after=after))
            self.assertFalse(result['ok']); self.assertNotEqual(result['database_changed'],False)
    def test_busy_watcher_or_bad_pin_stops_before_any_database_step(self):
        runner=Runner(); runner.unit='activating'; result=self.run_migration(runner)
        self.assertFalse(result['ok']); self.assertIs(result['database_changed'],False); self.assertEqual(runner.probes,0)
        runner=Runner(); result=self.run_migration(runner,migration_blob='f'*40)
        self.assertFalse(result['ok']); self.assertIs(result['database_changed'],False); self.assertEqual(runner.probes,0)
    def test_symlinked_migration_is_refused_without_running_php(self):
        path=self.root/M.MIGRATION; original=self.base/'original.php'; path.rename(original); path.symlink_to(original)
        runner=Runner(); result=self.run_migration(runner)
        self.assertFalse(result['ok']); self.assertIs(result['database_changed'],False); self.assertEqual(runner.probes,0)
    def test_lock_held_by_another_open_description_is_refused_before_php(self):
        other=os.open(self.work/'watcher.lock',os.O_RDONLY)
        try:
            fcntl.flock(other,fcntl.LOCK_EX|fcntl.LOCK_NB)
            runner=Runner(); result=self.run_migration(runner)
            self.assertFalse(result['ok']); self.assertIs(result['database_changed'],False); self.assertEqual(runner.probes,0)
        finally: os.close(other)
    def test_inconsistent_ledger_probe_is_refused_before_artisan(self):
        runner=Runner(before=schema(3,1)); result=self.run_migration(runner)
        self.assertFalse(result['ok']); self.assertFalse(result['migration_attempted']); self.assertIs(result['database_changed'],False)
        self.assertFalse(any('migrate' in args for args,_ in runner.commands))
    def test_verifier_failure_after_migration_does_not_erase_database_change(self):
        runner=Runner()
        def verify():
            if runner.probes==2: raise RuntimeError('PRIVATE after schema change')
        result=self.run_migration(runner,verify=verify)
        self.assertFalse(result['ok']); self.assertIs(result['database_changed'],True); self.assert_private(result)


if __name__=='__main__': unittest.main()
