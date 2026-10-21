#!/usr/bin/env python3
"""Focused private-reader and disclosure controls; no production execution."""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import tempfile
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('postbackup_subject', HERE/'deployment/diagnose_go_erp_postbackup_root.py')
subject = importlib.util.module_from_spec(spec)
spec.loader.exec_module(subject)

class PrivateBackupTests(unittest.TestCase):
    def setUp(self):
        self.temp = Path(tempfile.mkdtemp(prefix='postbackup-readonly-'))
        self.addCleanup(shutil.rmtree, self.temp)
        self.uid = patch.object(subject, 'UID', os.geteuid()); self.uid.start(); self.addCleanup(self.uid.stop)
        self.gid = patch.object(subject, 'GID', os.getegid()); self.gid.start(); self.addCleanup(self.gid.stop)
        self.root = self.temp/'app'; self.root.mkdir()
        self.backup = self.temp/'backup'; self.backup.mkdir(mode=0o700)
        for name in ('checks','publications'): (self.backup/name).mkdir(mode=0o700)
        self.manifest = {'expected_head': 'a'*40, 'before_source': {'sha256': 'b'*64},
            'after_source': {'sha256': 'c'*64}, 'sources': [
                {'path':'app/New.php','before_blob':None,'after_blob':'d'*40},
                {'path':'resources/views/admin/test.blade.php','before_blob':'e'*40,'after_blob':'f'*40}]}
        self.files = {'app/New.php':None, 'resources/views/admin/test.blade.php':
            {'blob':'e'*40,'uid':os.geteuid(),'gid':os.getegid(),'mode':0o644,'device':1,'inode':1}}
        for name in ('.env','bootstrap/cache/config.php','bootstrap/cache/routes-v7.php'):
            file=self.root/name;file.parent.mkdir(parents=True,exist_ok=True)
            file.write_bytes(b'SECRET_PRIVATE_VALUE='+name.encode());file.chmod(0o600)
            s=file.stat();self.files[name]={'blob':subject.blob(file.read_bytes()),'uid':s.st_uid,
                'gid':s.st_gid,'mode':0o600,'device':s.st_dev,'inode':s.st_ino}
        self.receipt={'version':1,'git_head':self.manifest['expected_head'],
            'before_source':self.manifest['before_source'],'after_source':self.manifest['after_source'],
            'manifest_blob':subject.MANIFEST_BLOB,'database':{'database_backup_sha256':'1'*64,
            'database_backup_bytes':10,'consistency':'innodb-consistent-snapshot'},
            'code_archive':{'sha256':'2'*64,'bytes':10},'files':self.files}
        self.save()
    def save(self):
        (self.backup/'receipt.json').write_text(json.dumps(self.receipt));(self.backup/'receipt.json').chmod(0o600)
    def report(self): return subject.inspect_backup(self.root,self.backup,self.manifest)
    def artifact(self,folder,name,content=b'PRIVATE_PUBLICATION_PAYLOAD'):
        p=self.backup/folder/name;p.write_bytes(content);p.chmod(0o600);return p
    def test_exact_three_private_restorations_and_no_disclosure(self):
        result=self.report();self.assertEqual(len(result['private_restoration']),3)
        self.assertTrue(all(result['private_restoration'].values()))
        encoded=json.dumps(result)
        for secret in ('SECRET_PRIVATE_VALUE',self.files['.env']['blob'],'database_backup_sha256'):
            self.assertNotIn(secret,encoded)
    def test_changed_private_file_reports_only_false(self):
        (self.root/'.env').write_bytes(b'NEW_SECRET')
        result=self.report();self.assertFalse(result['private_restoration']['.env'])
        self.assertNotIn('NEW_SECRET',json.dumps(result))
    def test_actor_group_writable_app_directories_and_cache_are_read_only_comparable(self):
        parent=self.root/'bootstrap/cache';parent.chmod(0o775)
        name='bootstrap/cache/config.php';(self.root/name).chmod(0o775)
        self.files[name]['mode']=0o775;self.save()
        self.assertTrue(self.report()['private_restoration'][name])
        self.files[name]['mode']=0o755;self.save()
        result=self.report();self.assertFalse(result['private_restoration'][name])
        self.assertNotIn('SECRET_PRIVATE_VALUE',json.dumps(result))
    def test_absent_recorded_cache_is_valid_only_while_still_absent(self):
        name='bootstrap/cache/routes-v7.php';(self.root/name).unlink();self.files[name]=None;self.save()
        self.assertTrue(self.report()['private_restoration'][name])
        (self.root/name).write_bytes(b'not approved');(self.root/name).chmod(0o600)
        self.assertFalse(self.report()['private_restoration'][name])
    def test_private_receipt_symlink_and_hardlink_rejected(self):
        file=self.backup/'receipt.json';real=self.backup/'kept';file.rename(real);file.symlink_to(real)
        with self.assertRaises(OSError):self.report()
        file.unlink();os.link(real,file)
        with self.assertRaises(subject.Stop):self.report()
    def test_private_receipt_mode_and_bounds_rejected(self):
        file=self.backup/'receipt.json';file.chmod(0o644)
        with self.assertRaises(subject.Stop):self.report()
        file.chmod(0o600);file.write_bytes(b'x'*(1024*1024+1))
        with self.assertRaises(subject.Stop):self.report()
    def test_backup_and_check_directory_links_rejected(self):
        alias=self.temp/'alias';alias.symlink_to(self.backup,target_is_directory=True)
        with self.assertRaises(OSError):subject.inspect_backup(self.root,alias,self.manifest)
        checks=self.backup/'checks';checks.rmdir();checks.symlink_to(self.temp,target_is_directory=True)
        with self.assertRaises(OSError):self.report()
    def test_private_file_symlink_rejected_without_following(self):
        target=self.root/'.env';target.unlink();target.symlink_to(self.backup/'receipt.json')
        with self.assertRaises(OSError):self.report()
    def test_wrong_manifest_and_unknown_receipt_paths_rejected(self):
        for key,value in [('manifest_blob','0'*40),('before_source',{}),('after_source',{})]:
            before=self.receipt[key];self.receipt[key]=value;self.save()
            with self.assertRaises(subject.Stop):self.report()
            self.receipt[key]=before
        self.files['private-extra']=None;self.save()
        with self.assertRaises(subject.Stop):self.report()
    def test_route_cache_roster_is_exact(self):
        self.files['bootstrap/cache/routes.php']=None;self.save()
        with self.assertRaises(subject.Stop):self.report()
    def test_known_blades_only_and_bounded_metadata_counts(self):
        key=hashlib.sha256(b'resources/views/admin/test.blade.php').hexdigest()
        for name in ('proposed-config.php','proposed-routes.php','compiled-'+key+'.php','private-services.php'):
            self.artifact('checks',name)
        self.artifact('publications','published-abcdefgh');self.artifact('publications','recovered-ijklmnop')
        result=self.report();self.assertEqual(result['checks'],{'proposed_config':True,'proposed_routes':True,'compiled_blades':1,'other_files':1})
        self.assertEqual(result['publications'],{'published':1,'recovered':1})
        self.assertNotIn('PRIVATE_PUBLICATION_PAYLOAD',json.dumps(result))
        self.artifact('checks','compiled-'+'0'*64+'.php')
        with self.assertRaises(subject.Stop):self.report()
    def test_publications_never_opened_or_hashed(self):
        self.artifact('publications','published-abcdefgh',b'ENV_SECRET')
        real_open=os.open
        def guarded(name,*args,**kwargs):
            if str(name).startswith(('published-','recovered-')):raise AssertionError('Publication bytes opened')
            return real_open(name,*args,**kwargs)
        with patch.object(subject.os,'open',side_effect=guarded):
            self.assertEqual(self.report()['publications']['published'],1)
    def test_publication_links_and_foreign_names_rejected(self):
        p=self.artifact('publications','published-abcdefgh');p.unlink();p.symlink_to(self.root/'.env')
        with self.assertRaises(subject.Stop):self.report()
        p.unlink();self.artifact('publications','arbitrary-name')
        with self.assertRaises(subject.Stop):self.report()
    def test_unsafe_directory_mode_rejected(self):
        self.backup.chmod(0o755)
        with self.assertRaises(subject.Stop):self.report()
    def test_diagnostics_redact_untrusted_exception_text(self):
        out,err=io.StringIO(),io.StringIO()
        with patch.object(subject,'execute',side_effect=ValueError('SECRET_TOKEN SQL password')):
            self.assertEqual(subject.main(['--release','a'*40],out,err),1)
        self.assertEqual(out.getvalue(),'');self.assertNotIn('SECRET',err.getvalue())
    def test_cli_accepts_no_arbitrary_path_or_repair_switch(self):
        for args in ([],['--backup','/tmp/foreign'],['--release','a'*40,'--repair'],['--release','not-a-sha']):
            with self.assertRaises(subject.Stop):subject.execute(args)
    def test_release_and_owner_pin_are_fixed(self):
        self.assertEqual(subject.BACKUP,Path('/home/fasakha/desktop-dashboard-release-backups/server-4cb99kno'))
        self.assertEqual(subject.ROOT,Path('/home/fasakha/public_html'))
        self.assertEqual(subject.OWNER_BLOB,'6ceff5c798411e42ca4d7673764eb72ded3ed2e6')

class RuntimeProbeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.manifest = json.loads(subprocess.check_output(['git', '-c', 'safe.directory='+str(HERE),
            '-C', str(HERE), 'cat-file', 'blob', subject.MANIFEST_BLOB]))
    def sample(self):
        migrations = [{'migration': Path(name).stem, 'records': 0,
            'tables': [{'name': table, 'exists': False} for table in tables]}
            for name, tables in zip(self.manifest['migrations'], subject.MIGRATION_TABLES)]
        views = [{'source': row['path'], 'target': 'storage/framework/views/'+'a'*40+'.php', 'metadata': None}
            for row in self.manifest['sources'] if row['path'].startswith('resources/views/')
            and row['path'].endswith('.blade.php')]
        return {'kind': 'go-erp-postbackup-read-only-probe', 'available': True, 'framework': '8.83.29',
            'driver': 'mysql', 'migrations': migrations, 'compiled_views': views}
    def test_actual_migration_and_compiled_view_roster(self):
        value = self.sample()
        self.assertEqual(subject.validate_probe(value, self.manifest), value)
        self.assertEqual(sum(len(row['tables']) for row in value['migrations']), 11)
    def test_extra_private_fields_and_unknown_names_are_rejected(self):
        for field in ('message', 'password', 'token'):
            value = self.sample(); value[field] = 'SECRET'
            with self.assertRaises(subject.Stop): subject.validate_probe(value, self.manifest)
        value = self.sample(); value['migrations'][0]['tables'][0]['name'] = 'customer_private_table'
        with self.assertRaises(subject.Stop): subject.validate_probe(value, self.manifest)
    def test_compiled_target_scope_and_metadata_shape_are_fixed(self):
        value = self.sample(); row = value['compiled_views'][0]
        row['metadata'] = {'uid': 0, 'gid': 0, 'mode': '0644', 'links': 1}
        subject.validate_probe(value, self.manifest)
        row['target'] = '.env'
        with self.assertRaises(subject.Stop): subject.validate_probe(value, self.manifest)
        row['target'] = 'storage/framework/views/'+'a'*40+'.php'; row['metadata']['uid'] = True
        with self.assertRaises(subject.Stop): subject.validate_probe(value, self.manifest)
    def test_failure_has_only_bounded_phase_and_class(self):
        value = {'kind': 'go-erp-postbackup-read-only-probe', 'available': False,
            'phase': 'BOOTSTRAP', 'error_class': 'RuntimeException'}
        self.assertEqual(subject.validate_probe(value, self.manifest), value)
        value['error_class'] = 'SECRET CONNECTION DETAILS'
        with self.assertRaises(subject.Stop): subject.validate_probe(value, self.manifest)
    def test_invalid_subprocess_output_is_redacted_and_temporary_tree_removed(self):
        class Result:
            returncode = 1
            stdout = b'SECRET SQL password'
            stderr = b'SECRET /root/private'
        paths = []
        def runner(args, **kwargs):
            paths.append(Path(args[-1]).parent)
            self.assertEqual(kwargs['stdin'], subprocess.DEVNULL)
            self.assertEqual(kwargs['timeout'], 90)
            return Result()
        owner = {'APP': 'a'*40, 'source_file': lambda *_: b'reviewed fixture'}
        with patch.object(subject.os, 'chown'):
            result = subject.run_probe(owner, {'manifest': self.manifest}, 'b'*40, runner)
        self.assertFalse(result['available']); self.assertEqual(result['phase'], 'PROCESS')
        self.assertNotIn('SECRET', json.dumps(result)); self.assertFalse(paths[0].exists())
    def test_probe_timeout_stops_the_entire_child_process_group(self):
        class Process:
            pid = 12345
            calls = 0
            def communicate(self, timeout):
                self.calls += 1
                if self.calls == 1:
                    raise subprocess.TimeoutExpired('PRIVATE_COMMAND', timeout)
                return b'', b'PRIVATE_STDERR'
        with patch.object(subject.subprocess, 'Popen', return_value=Process()) as launch:
            with patch.object(subject.os, 'killpg') as stop:
                with self.assertRaisesRegex(subject.Stop, 'PROBE_PROCESS_UNAVAILABLE'):
                    subject.probe_process(['pinned-probe'], env={}, stdin=subprocess.DEVNULL,
                        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=90)
                stop.assert_called_once_with(12345, signal.SIGTERM)
                self.assertTrue(launch.call_args.kwargs['start_new_session'])

if __name__=='__main__':unittest.main()
