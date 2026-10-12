#!/usr/bin/env python3
"""Fault checks for reviewed publication, retained ledgers and concurrent edits."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import sys
import tarfile
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

HERE=Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('desktop_install_test_subject',HERE/'deployment/apply_desktop_dashboard_server.py')
installer=importlib.util.module_from_spec(spec);spec.loader.exec_module(installer)

class Fixture:
    def __init__(self):
        self.folder=Path(tempfile.mkdtemp(prefix='desktop-install-fault-'))
        self.root=self.folder/'public_html';self.root.mkdir();(self.root/'.git').mkdir()
        self.payload=self.folder/'payload';self.payload.mkdir()
        self.uid=os.geteuid();self.calls=[];self.fail=None;self.event=None
        self.head='a'*40;self.migrations=[]
        self.env=b'META_APP_SECRET="kept privately"\r\nWHATSAPP_TOKEN="retained"\r\nDESKTOP_DASHBOARD_ENABLED=false\r\nexport DESKTOP_DASHBOARD_LOCAL = true\r\nOTHER=unchanged\r\n'
        self.write(self.root/'.env',self.env,0o600)
        self.write(self.root/'app/Services/Existing.php',b'<?php /* live WhatsApp-compatible service */\n')
        self.write(self.root/'routes/api.php',b'<?php /* live routes retained */\n')
        self.write(self.root/'config/meta.php',b'<?php return ["protected"=>true];\n')
        self.write(self.root/'public/js/dashboard-whatsapp-inbox.js',b'/* live UI outside fingerprint */\n')
        self.write(self.root/'bootstrap/cache/config.php',b'<?php return ["meta"=>["secret"=>"preserve"],"desktop_dashboard"=>["enabled"=>false,"local"=>false]];\n',0o600)
        self.write(self.root/'bootstrap/cache/routes-v7.php',b'<?php /* original route cache */\n')
        self.original_config=(self.root/'bootstrap/cache/config.php').read_bytes()
        self.original_routes=(self.root/'bootstrap/cache/routes-v7.php').read_bytes()
        self.write(self.payload/'deployment/desktop_dashboard_release.php',b'<?php /* fixture helper */\n')
        rows=[]
        for index,path in enumerate(installer.MIGRATIONS):
            data=('<?php /* additive migration '+str(index)+' */\n').encode();self.write(self.payload/path,data)
            rows.append({'path':path,'before_blob':None,'after_blob':installer.blob(data),'phase':0})
        for path,data,phase in [
            ('app/Services/NewDesktop.php',b'<?php /* new desktop dependency */\n',1),
            ('app/Services/Existing.php',b'<?php /* compatible service plus desktop guard */\n',2),
            ('routes/api.php',b'<?php /* live routes plus authenticated desktop API */\n',2),
        ]:
            self.write(self.payload/path,data);old=self.root/path
            rows.append({'path':path,'before_blob':installer.blob(old.read_bytes())if old.exists()else None,'after_blob':installer.blob(data),'phase':phase})
        before=installer.source_fingerprint(self.root,self.uid)
        copy=self.folder/'target';shutil.copytree(self.root,copy)
        for row in rows:self.write(copy/row['path'],(self.payload/row['path']).read_bytes())
        after=installer.source_fingerprint(copy,self.uid);shutil.rmtree(copy)
        self.manifest={'version':1,'expected_head':self.head,'before_source':before,'after_source':after,'sources':rows,
            'protected':[{'path':'public/js/dashboard-whatsapp-inbox.js','blob':installer.blob((self.root/'public/js/dashboard-whatsapp-inbox.js').read_bytes())}],
            'migrations':list(installer.MIGRATIONS)}
        self.save_manifest()
    @staticmethod
    def write(path,data,mode=0o644):
        path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data);path.chmod(mode)
    def save_manifest(self):
        self.manifest_path=self.payload/'deployment/desktop_dashboard_server_manifest.json'
        self.write(self.manifest_path,(json.dumps(self.manifest)+'\n').encode())
        self.pin=installer.blob(self.manifest_path.read_bytes())
    def runner(self,root,args,capture=False,timeout=120,private=None):
        self.calls.append(args)
        if self.event:self.event(args)
        if args[:2]==['/usr/bin/git','rev-parse']:return 0,(self.head+'\n').encode()
        if args[0]=='/usr/bin/tar':
            if '-cpf'in args:
                if self.fail=='code_backup':return 1,b''
                index=args.index('-cpf');target=Path(args[index+1])
                with tarfile.open(target,'w:')as archive:
                    for name in args[index+2:]:
                        if(self.root/name).exists():archive.add(self.root/name,arcname=name)
                target.chmod(0o600)
            return 0,b''
        if '-l'in args:return 0,b''
        if 'artisan'in args:
            self.migrations.append(args[3].split('=',1)[1])
            # Represents additive DB records, never removed during code rollback.
            self.write(self.folder/'retained-business-operation',b'existing live order retained',0o600)
            return (1,b'')if self.fail=='migration'else(0,b'')
        if '-r'in args:
            if self.fail=='route_cache':return 1,b''
            self.write(Path(args[-1]),b'<?php /* new authenticated cached routes */\n',0o600)
            return 0,b''
        mode=args[2];directory=Path(args[4])
        if mode=='preflight':return 0,json.dumps({'route_cache':'bootstrap/cache/routes-v7.php','config_cache':'bootstrap/cache/config.php','database_estimated_bytes':10000}).encode()
        if mode=='backup':
            # Source/env/cache must still have the reviewed original bytes when
            # the complete DB backup starts, even after code archive finished.
            assert installer.source_fingerprint(self.root,self.uid)==self.manifest['before_source']
            assert(self.root/'.env').read_bytes()==self.env
            assert(self.root/'bootstrap/cache/config.php').read_bytes()==self.original_config
            if self.fail=='database_backup':return 1,b''
            data=b'complete private database including orders and routines\n'
            self.write(directory/'database.sql',data,0o600)
            return 0,json.dumps({'database_backup_sha256':hashlib.sha256(data).hexdigest(),'database_backup_bytes':len(data),'consistency':'innodb-consistent-snapshot'}).encode()
        if mode=='config':
            self.write(directory/'proposed-config.php',b'<?php return ["meta"=>["secret"=>"preserve"],"desktop_dashboard"=>["enabled"=>true,"local"=>false]];\n',0o600)
            return 0,b'{"configuration_ready":true}'
        if mode=='verify':
            if self.fail=='verify':return 1,b''
            return 0,json.dumps({'source':self.manifest['after_source'],'authentication_checked':4,'enabled':True,'local':False}).encode()
        raise AssertionError('Unexpected runner operation')
    def install(self):return installer.install(self.root,self.uid,self.manifest_path,self.pin,self.runner,web_checker=lambda:4)
    def close(self):shutil.rmtree(self.folder)

class InstallTests(unittest.TestCase):
    def setUp(self):self.f=Fixture()
    def tearDown(self):self.f.close()
    def assert_restored(self):
        self.assertEqual(installer.source_fingerprint(self.f.root,self.f.uid),self.f.manifest['before_source'])
        self.assertEqual((self.f.root/'.env').read_bytes(),self.f.env)
        self.assertEqual((self.f.root/'bootstrap/cache/config.php').read_bytes(),self.f.original_config)
        self.assertEqual((self.f.root/'bootstrap/cache/routes-v7.php').read_bytes(),self.f.original_routes)
    def test_additive_success_backup_before_mutation_and_only_six_migrations(self):
        backup,result=self.f.install()
        self.assertEqual(self.f.migrations,list(installer.MIGRATIONS))
        self.assertEqual(result['source'],self.f.manifest['after_source'])
        self.assertEqual((self.f.root/'.env').read_bytes(),installer.patch_env(self.f.env))
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode),0o700)
        self.assertEqual(stat.S_IMODE((backup/'database.sql').stat().st_mode),0o600)
        self.assertTrue((backup/'code.tar').is_file());self.assertTrue((backup/'activated.json').is_file())
        self.assertFalse(any('checkout'in call or 'reset'in call or 'config:cache'in call or 'route:clear'in call or 'view:clear'in call for call in self.f.calls))
    def test_unknown_source_stops_before_backup(self):
        self.f.write(self.f.root/'app/Services/Unexpected.php',b'<?php /* concurrent update */')
        with self.assertRaisesRegex(installer.SafeError,'SOURCE_CHANGED'):self.f.install()
        self.assertFalse(any(call[0]=='/usr/bin/tar'for call in self.f.calls));self.assertEqual(self.f.migrations,[])
    def test_unknown_external_gui_asset_stops_before_backup(self):
        self.f.write(self.f.root/'public/js/dashboard-whatsapp-inbox.js',b'new live WhatsApp changes')
        with self.assertRaisesRegex(installer.SafeError,'PROTECTED_ASSET_CHANGED'):self.f.install()
        self.assertFalse(any(call[0]=='/usr/bin/tar'for call in self.f.calls))
    def test_wrong_staged_blob_stops_before_backup(self):
        self.f.write(self.f.payload/'app/Services/NewDesktop.php',b'<?php tampered')
        with self.assertRaisesRegex(installer.SafeError,'AFTER_SOURCE_MISMATCH'):self.f.install()
        self.assert_restored();self.assertEqual(self.f.migrations,[])
    def test_code_archive_failure_has_no_mutation(self):
        self.f.fail='code_backup'
        with self.assertRaisesRegex(installer.SafeError,'CODE_BACKUP_FAILED'):self.f.install()
        self.assert_restored();self.assertEqual(self.f.migrations,[])
    def test_database_backup_failure_has_no_mutation(self):
        self.f.fail='database_backup'
        with self.assertRaisesRegex(installer.SafeError,'PRIVATE_CHECK_FAILED'):self.f.install()
        self.assert_restored();self.assertEqual(self.f.migrations,[])
    def test_postflight_failure_restores_original_inode_and_keeps_operations(self):
        path=self.f.root/'app/Services/Existing.php';inode=path.stat().st_ino
        alias=self.f.folder/'legacy-hardlink';os.link(path,alias);old=alias.read_bytes()
        self.f.fail='verify'
        with self.assertRaisesRegex(installer.SafeError,'FILES_RESTORED'):self.f.install()
        self.assert_restored();self.assertEqual(path.stat().st_ino,inode);self.assertEqual(alias.stat().st_ino,inode);self.assertEqual(alias.read_bytes(),old)
        self.assertTrue((self.f.folder/'retained-business-operation').exists());self.assertEqual(self.f.migrations,list(installer.MIGRATIONS))
    def test_migration_failure_restores_files_retains_additive_database(self):
        self.f.fail='migration'
        with self.assertRaisesRegex(installer.SafeError,'FILES_RESTORED'):self.f.install()
        self.assert_restored();self.assertEqual(self.f.migrations,[installer.MIGRATIONS[0]])
        self.assertTrue((self.f.folder/'retained-business-operation').exists())
    def test_route_cache_failure_restores_source_environment_and_meta_cache(self):
        self.f.fail='route_cache'
        with self.assertRaisesRegex(installer.SafeError,'FILES_RESTORED'):self.f.install()
        self.assert_restored()
    def test_concurrent_environment_change_is_retained(self):
        original=installer.base.replace_file;peer=b'META_APP_SECRET="newly configured"\n'
        def racing(path,*args,**kwargs):
            if path==self.f.root/'.env':self.f.write(path,peer,0o600)
            return original(path,*args,**kwargs)
        installer.base.replace_file=racing
        try:
            with self.assertRaisesRegex(installer.SafeError,'FILES_RESTORED'):self.f.install()
        finally:installer.base.replace_file=original
        self.assertEqual((self.f.root/'.env').read_bytes(),peer)
        self.assertEqual(installer.source_fingerprint(self.f.root,self.f.uid),self.f.manifest['before_source'])
    def test_concurrent_entrypoint_change_retains_dependencies(self):
        peer=b'<?php /* concurrent routes using new dependency */\n'
        def event(args):
            if len(args)>2 and args[2]=='verify':self.f.write(self.f.root/'routes/api.php',peer)
        self.f.event=event
        with self.assertRaisesRegex(installer.SafeError,'CONCURRENT_FILES_RETAINED'):self.f.install()
        self.assertEqual((self.f.root/'routes/api.php').read_bytes(),peer)
        self.assertTrue((self.f.root/'app/Services/NewDesktop.php').exists())
        self.assertTrue((self.f.folder/'retained-business-operation').exists())
    def test_exception_immediately_after_atomic_exchange_recovers_both_versions(self):
        original=installer.base.atomic_exchange;failed=False
        def interrupted(source,destination):
            nonlocal failed
            original(source,destination)
            if not failed and destination==self.f.root/'app/Services/Existing.php':failed=True;raise RuntimeError('simulated post-syscall interruption')
        installer.base.atomic_exchange=interrupted
        try:
            with self.assertRaisesRegex(installer.SafeError,'FILES_RESTORED'):self.f.install()
        finally:installer.base.atomic_exchange=original
        self.assert_restored();self.assertTrue(failed)
    def test_manifest_rejects_unreviewed_business_migration(self):
        self.f.manifest['sources'].append({'path':'database/migrations/2026_10_12_delete_orders.php','before_blob':None,'after_blob':'b'*40,'phase':2});self.f.save_manifest()
        with self.assertRaisesRegex(installer.SafeError,'MIGRATION_SCOPE_INVALID'):self.f.install()
        self.assertEqual(self.f.calls,[])
    def test_manifest_requires_dependency_first_publication(self):
        self.f.manifest['sources'].reverse();self.f.save_manifest()
        with self.assertRaisesRegex(installer.SafeError,'PUBLICATION_ORDER_INVALID'):self.f.install()
    def test_environment_patch_keeps_other_settings_bytes(self):
        value=installer.patch_env(self.f.env)
        self.assertIn(b'META_APP_SECRET="kept privately"\r\nWHATSAPP_TOKEN="retained"\r\n',value)
        self.assertIn(b'OTHER=unchanged\r\n',value)
        self.assertEqual(value.count(b'DESKTOP_DASHBOARD_ENABLED='),1)
        self.assertEqual(value.count(b'DESKTOP_DASHBOARD_LOCAL='),1)
        self.assertTrue(value.endswith(b'DESKTOP_DASHBOARD_ENABLED=true\nDESKTOP_DASHBOARD_LOCAL=false\n'))
    def test_symlink_source_is_refused(self):
        os.symlink(self.f.root/'config/meta.php',self.f.root/'app/symlink.php')
        with self.assertRaisesRegex(installer.SafeError,'SOURCE_LINK'):self.f.install()
    def test_payload_scope_rejects_parent_traversal(self):
        self.f.manifest['sources'][0]['path']='app/../.env';self.f.save_manifest()
        with self.assertRaisesRegex(installer.SafeError,'SOURCE_INVALID'):self.f.install()
    def test_web_auth_failure_restores_only_own_files_keeps_live_operations(self):
        def failed_web():raise installer.SafeError('DESKTOP_WEB_AUTH_VERIFICATION_FAILED')
        with self.assertRaisesRegex(installer.SafeError,'FILES_RESTORED'):
            installer.install(self.f.root,self.f.uid,self.f.manifest_path,self.f.pin,self.f.runner,web_checker=failed_web)
        self.assert_restored();self.assertTrue((self.f.folder/'retained-business-operation').exists())
    def test_insufficient_backup_space_stops_before_archive_or_migration(self):
        with patch.object(installer.shutil,'disk_usage',return_value=SimpleNamespace(free=0)):
            with self.assertRaisesRegex(installer.SafeError,'BACKUP_SPACE_INSUFFICIENT'):self.f.install()
        self.assert_restored();self.assertEqual(self.f.migrations,[])
        self.assertFalse(any(call[0]=='/usr/bin/tar'for call in self.f.calls))
    def test_existing_source_cannot_precede_new_dependencies(self):
        row=next(r for r in self.f.manifest['sources']if r['path']=='app/Services/Existing.php');row['phase']=1;self.f.save_manifest()
        with self.assertRaisesRegex(installer.SafeError,'EXISTING_SOURCE_ORDER_INVALID'):self.f.install()
    def test_exact_bootstrap_cache_symlink_is_rejected(self):
        original=self.f.root/'bootstrap/cache';historical=self.f.root/'historical-cache'
        original.rename(historical);os.symlink(historical,original)
        with self.assertRaisesRegex(installer.SafeError,'SOURCE_LINK'):self.f.install()
        self.assertEqual(self.f.migrations,[])
    def test_unowned_publication_reports_approved_path_uid_and_mode(self):
        target=self.f.root/'app/Services/Existing.php';original=Path.lstat
        def metadata(path):
            if path==target:return SimpleNamespace(st_uid=999999,st_mode=0o100640)
            return original(path)
        with patch.object(Path,'lstat',metadata):
            with self.assertRaisesRegex(installer.SafeError,r'DESKTOP_SOURCE_OWNER_NEEDS_REVIEW: app/Services/Existing.php; UID=999999; MODE=0640'):
                installer.publication_snapshot(self.f.root,'app/Services/Existing.php',self.f.uid)
        self.assert_restored()

class WebProbeTests(unittest.TestCase):
    def test_fixed_host_correct_methods_without_credentials_or_body_reads(self):
        calls=[]
        class Response:
            status=401
            def read(self,*_):raise AssertionError('Private response body must not be read')
        class Connection:
            def __init__(self,host,timeout):self.host=host;self.timeout=timeout
            def request(self,method,path,body=None,headers=None):calls.append((self.host,self.timeout,method,path,body,headers))
            def getresponse(self):return Response()
            def close(self):pass
        with patch.object(installer.http.client,'HTTPSConnection',Connection):self.assertEqual(installer.verify_web_auth(),4)
        self.assertEqual([(row[2],row[3])for row in calls],[('GET','/api/desktop-dashboard/bootstrap'),('POST','/api/desktop-dashboard/commands'),('POST','/api/desktop-dashboard/remote-attempts'),('GET','/api/desktop-dashboard/media')])
        self.assertTrue(all(row[0]=='fasakhaninja.com'and 0<row[1]<=10 and row[5]['Accept']=='application/json'and 'Authorization'not in row[5]and 'Cookie'not in row[5]for row in calls))
        self.assertEqual([row[4]for row in calls],[None,b'{}',b'{}',None])
    def test_redirect_is_never_followed(self):
        calls=[]
        class Connection:
            def __init__(self,host,timeout):pass
            def request(self,method,path,**_):calls.append(path)
            def getresponse(self):return SimpleNamespace(status=302)
            def close(self):pass
        with patch.object(installer.http.client,'HTTPSConnection',Connection),patch.object(installer.time,'sleep'):
            with self.assertRaisesRegex(installer.SafeError,'WEB_AUTH_VERIFICATION_FAILED'):installer.verify_web_auth()
        self.assertEqual(calls,['/api/desktop-dashboard/bootstrap','/api/desktop-dashboard/bootstrap'])

if __name__=='__main__':unittest.main()
