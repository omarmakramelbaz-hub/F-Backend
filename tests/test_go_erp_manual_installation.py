"""Exercise app/unit commit and rollback without native state or service writes."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('manual_install',ROOT/'deployment/apply_go_erp_manual_updates_root.py')
subject=importlib.util.module_from_spec(spec);spec.loader.exec_module(subject)


class InstallationTests(unittest.TestCase):
    def setUp(self):
        temporary=tempfile.TemporaryDirectory(prefix='manual-install-')
        self.addCleanup(temporary.cleanup);self.work=Path(temporary.name)
        self.root=self.work/'application';self.root.mkdir(mode=0o755)
        self.control=self.work/'var/manual';self.control.parent.mkdir(mode=0o755)
        self.releases=self.work/'opt/manual/releases';self.releases.parent.parent.mkdir(mode=0o755)
        self.systemd=self.work/'systemd';self.systemd.mkdir(mode=0o755)
        self.owner=types.SimpleNamespace(pw_uid=os.geteuid(),pw_gid=os.getegid(),pw_name='fixture-web')
        self.package=(ROOT/'deployment/apply_paymob_order_confirmation_root.py').read_bytes()
        self.before={};self.app={};self.pins={}
        names=list(subject.APP_PINS)
        for index,name in enumerate(names):
            path=self.root/name;path.parent.mkdir(mode=0o755,parents=True,exist_ok=True)
            content=('new:'+name+'\n').encode();self.app[name]=content
            if index in (0,4):old=None
            else:
                old=('old:'+name+'\n').encode();path.write_bytes(old);path.chmod(0o644)
            self.before[name]=old;self.pins[name]=(subject.sha(old) if old else None,subject.sha(content))
        self.program={'go_erp_manual_updates_root.py':b'print("daemon")\n',
            'go_erp_manual_tick_root.py':b'print("tick")\n'}
        for name,value in {'APP_PINS':self.pins,'PROGRAM_PINS':{k:subject.sha(v) for k,v in self.program.items()},
                'RELEASES':self.releases}.items():
            scoped=patch.object(subject,name,value);scoped.start();self.addCleanup(scoped.stop)
        self.calls=[];self.fail_when=None
        (self.root/'bootstrap/cache').mkdir(mode=0o755,parents=True)
        (self.root/'storage/framework/views').mkdir(mode=0o755,parents=True)
        self.cache=self.root/'bootstrap/cache/routes-v7.php';self.cache.write_bytes(b'<?php // routes');self.cache.chmod(0o644)
        self.config=self.root/'bootstrap/cache/config.php';self.config.write_bytes(b'<?php // retained config');self.config.chmod(0o644)
        marker=('<?php /**PATH '+str(self.root/'resources/views/admin/desktop-dashboard.blade.php')+' ENDPATH**/').encode()
        self.view=self.root/'storage/framework/views'/('a'*40+'.php');self.view.write_bytes(marker);self.view.chmod(0o644)
        self.other_view=self.root/'storage/framework/views'/('b'*40+'.php');self.other_view.write_bytes(b'<?php unrelated');self.other_view.chmod(0o644)

    def runner(self,args,timeout=30):
        self.calls.append(tuple(args))
        if self.fail_when and self.fail_when(args):raise subject.Stop('INJECTED_FAILURE')
        if 'show' in args:
            return 'active' if args[2]=='go-erp-manual-updates.path' else 'inactive'
        return ''

    def perform(self):
        return subject.perform(self.root,self.owner,self.package,self.app,self.program,'a'*40,
            control=self.control,releases=self.releases,systemd=self.systemd,runner=self.runner)

    def original_app(self):
        for name,content in self.before.items():
            path=self.root/name
            self.assertEqual(path.read_bytes() if path.exists() else None,content,name)

    def test_success_installs_fixed_code_units_and_only_the_target_caches(self):
        result=self.perform()
        for name,content in self.app.items():self.assertEqual((self.root/name).read_bytes(),content)
        runtime=Path(result['runtime'])
        self.assertEqual(stat.S_IMODE(runtime.stat().st_mode),0o555)
        self.assertEqual({p.name for p in runtime.iterdir()},set(self.program))
        for path in runtime.iterdir():self.assertEqual(stat.S_IMODE(path.stat().st_mode),0o444)
        self.assertFalse(self.cache.exists());self.assertFalse(self.view.exists())
        self.assertEqual(self.config.read_bytes(),b'<?php // retained config')
        self.assertEqual(self.other_view.read_bytes(),b'<?php unrelated')
        status=json.loads((self.control/'shared/status.json').read_bytes())
        self.assertEqual((status['state'],status['nonce']),('idle',None))
        self.assertFalse((self.control/'requests/pending.json').exists())
        self.assertFalse((self.control/'private/active.json').exists())
        unit=(self.systemd/'go-erp-manual-updates.path').read_text()
        self.assertIn('PathExists=/var/lib/go-erp-manual-updates/requests/pending.json',unit)
        self.assertIn('PathExists=/var/lib/go-erp-manual-updates/private/active.json',unit)
        self.assertNotIn('timer',unit)
        self.assertFalse(any(a[:2]==('/usr/bin/systemctl','start') and a[-1].endswith('.service') for a in self.calls))

    def test_rerun_preserves_status_pending_active_and_root_ledger(self):
        self.perform()
        records={self.control/'requests/pending.json':b'{"owner":"request"}\n',
            self.control/'private/active.json':b'{"authorized":"job"}\n',
            self.control/'private/last-request.json':b'{"nonce":"retained"}\n'}
        for path,raw in records.items():subject.atomic_write(path,raw,0,0,0o600)
        status=self.control/'shared/status.json';value=json.loads(status.read_bytes());value.update(state='waiting-ci',nonce='a'*32)
        subject.atomic_write(status,subject.canonical(value),0,self.owner.pw_gid,0o640)
        before={p:p.read_bytes() for p in [*records,status]}
        result=self.perform()
        self.assertFalse(result['source_changed'])
        self.assertEqual({p:p.read_bytes() for p in before},before)

    def test_unit_activation_failure_rolls_back_app_units_and_cache(self):
        self.fail_when=lambda args:args[:3]==['/usr/bin/systemctl','enable','--now']
        with self.assertRaisesRegex(subject.Stop,'INJECTED_FAILURE'):self.perform()
        self.original_app()
        self.assertFalse(any((self.systemd/name).exists() for name in subject.UNIT_NAMES))
        self.assertTrue(self.cache.exists());self.assertTrue(self.view.exists())
        self.assertIn(('/usr/bin/systemctl','disable','go-erp-manual-updates.path'),self.calls)
        self.assertFalse(any('go-erp-live-updates.timer' in a and 'enable' in a for a in self.calls))

    def test_existing_web_cache_permissions_are_preserved_on_rollback(self):
        self.cache.chmod(0o664);self.view.chmod(0o640)
        self.fail_when=lambda args:args[:3]==['/usr/bin/systemctl','enable','--now']
        with self.assertRaisesRegex(subject.Stop,'INJECTED_FAILURE'):self.perform()
        self.assertEqual(stat.S_IMODE(self.cache.stat().st_mode),0o664)
        self.assertEqual(stat.S_IMODE(self.view.stat().st_mode),0o640)

    def test_post_rename_app_write_failure_is_tracked_and_restored(self):
        original=subject.atomic_write;raised=False
        def write(path,*args,**kwargs):
            nonlocal raised
            original(path,*args,**kwargs)
            if path==self.root/'routes/desktop_dashboard.php' and not raised:
                raised=True;raise OSError('after rename')
        with patch.object(subject,'atomic_write',side_effect=write),self.assertRaises(OSError):self.perform()
        self.original_app();self.assertTrue(self.cache.exists())

    def test_post_rename_unit_write_failure_restores_written_unit_and_app(self):
        original=subject.atomic_write;raised=False
        def write(path,*args,**kwargs):
            nonlocal raised
            original(path,*args,**kwargs)
            if path==self.systemd/subject.UNIT_NAMES[0] and not raised:
                raised=True;raise OSError('after rename')
        with patch.object(subject,'atomic_write',side_effect=write),self.assertRaises(OSError):self.perform()
        self.original_app();self.assertFalse((self.systemd/subject.UNIT_NAMES[0]).exists())

    def test_post_activation_failure_stops_new_service_before_restoring_source(self):
        original=subject.atomic_write
        running=False;children=0;restored=[]
        def runner(args,timeout=30):
            nonlocal running,children
            self.calls.append(tuple(args))
            if args[:3]==['/usr/bin/systemctl','enable','--now']:
                # An existing explicit request starts a broker, but its native
                # child cannot obtain the installer's watcher lock yet.
                running=True;children+=1
            elif args[:3]==['/usr/bin/systemctl','stop','go-erp-manual-updates.service']:
                running=False
            if 'show' in args:
                if args[2]=='go-erp-manual-updates.path':return 'active'
                if args[2]=='go-erp-manual-updates.service':return 'active' if running else 'inactive'
                return 'inactive'
            return ''
        def write(path,raw,*args,**kwargs):
            if path==self.control/'private/installation.json':raise OSError('receipt write failure')
            relative=str(path.relative_to(self.root)) if path.is_relative_to(self.root) else None
            if relative in self.before and raw==self.before[relative]:
                self.assertFalse(running,'an activated service survived into app rollback')
                restored.append(relative)
            return original(path,raw,*args,**kwargs)
        with patch.object(subject,'atomic_write',side_effect=write),self.assertRaises(OSError):
            subject.perform(self.root,self.owner,self.package,self.app,self.program,'a'*40,
                control=self.control,releases=self.releases,systemd=self.systemd,runner=runner)
        self.assertEqual(children,1);self.assertFalse(running);self.assertTrue(restored)
        self.original_app();self.assertFalse(any((self.systemd/name).exists() for name in subject.UNIT_NAMES))

    def test_foreign_application_content_refuses_before_provisioning(self):
        target=self.root/'routes/desktop_dashboard.php';target.write_bytes(b'foreign')
        with self.assertRaisesRegex(RuntimeError,'BASELINE_MISMATCH'):self.perform()
        self.assertEqual(target.read_bytes(),b'foreign');self.assertFalse(self.control.exists())
        self.assertTrue(self.cache.exists())

    def test_foreign_unit_is_preserved_and_application_not_committed(self):
        unit=self.systemd/subject.UNIT_NAMES[0];unit.write_bytes(b'foreign service');unit.chmod(0o644)
        with self.assertRaisesRegex(subject.Stop,'FOREIGN_MANUAL_UNIT'):self.perform()
        self.original_app();self.assertEqual(unit.read_bytes(),b'foreign service');self.assertTrue(self.cache.exists())

    def test_failed_php_preflight_does_not_commit_app_or_create_queue(self):
        self.fail_when=lambda args:args[:1]==['/usr/sbin/runuser']
        with self.assertRaisesRegex(subject.Stop,'INJECTED_FAILURE'):self.perform()
        self.original_app();self.assertFalse(self.control.exists());self.assertTrue(self.cache.exists())

    def test_unsafe_app_ancestor_and_hardlinks_are_rejected(self):
        target=self.root/'routes/desktop_dashboard.php'
        os.link(target,self.work/'hardlink')
        with self.assertRaisesRegex(subject.Stop,'FILE_UNSAFE'):self.perform()
        (self.work/'hardlink').unlink()
        old=self.root/'routes';moved=self.root/'real-routes';old.rename(moved);old.symlink_to(moved)
        with self.assertRaises(OSError):self.perform()

    def test_root_program_pins_and_immutable_runtime_tampering_are_rejected(self):
        with patch.dict(self.program,{'go_erp_manual_tick_root.py':b'changed'}),self.assertRaisesRegex(subject.Stop,'RUNTIME_PINS_INVALID'):
            self.perform()
        result=self.perform();runtime=Path(result['runtime'])
        path=runtime/'go_erp_manual_tick_root.py';path.chmod(0o644);path.write_bytes(b'tampered');path.chmod(0o444)
        with self.assertRaisesRegex(subject.Stop,'RUNTIME_CONTENT_CHANGED'):self.perform()

    def test_symlink_write_ancestor_cannot_escape_the_fixed_directory(self):
        outside=self.work/'outside';outside.mkdir();(self.work/'link').symlink_to(outside)
        with self.assertRaises(OSError):subject.atomic_write(self.work/'link/target',b'blocked',0,0,0o600)
        self.assertFalse((outside/'target').exists())

    def test_current_application_pins_match_the_actual_payloads(self):
        # Read final constants from a fresh module, independent of the fixture's
        # synthetic pins, so a UI edit cannot silently stale the root installer.
        value=types.ModuleType('real_pins');value.__file__=str(ROOT/'deployment/apply_go_erp_manual_updates_root.py')
        exec(compile(Path(value.__file__).read_bytes(),value.__file__,'exec'),value.__dict__)
        for path,(_,after) in value.APP_PINS.items():self.assertEqual(subject.sha((ROOT/path).read_bytes()),after,path)

    def test_current_root_program_pins_match_the_exact_runtime_bytes(self):
        value=types.ModuleType('real_program_pins');value.__file__=str(ROOT/'deployment/apply_go_erp_manual_updates_root.py')
        exec(compile(Path(value.__file__).read_bytes(),value.__file__,'exec'),value.__dict__)
        self.assertEqual(set(value.PROGRAM_PINS),{'go_erp_manual_updates_root.py','go_erp_manual_tick_root.py'})
        for name,expected in value.PROGRAM_PINS.items():
            self.assertEqual(subject.sha((ROOT/'deployment'/name).read_bytes()),expected,name)


if __name__=='__main__':unittest.main()
