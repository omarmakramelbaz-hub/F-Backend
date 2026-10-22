#!/usr/bin/env python3
"""Immutable watcher runtime switch, untouched credentials/state and rollback tests."""
import fcntl
import hashlib
import importlib.util
import contextlib
import io
import json
import os
from pathlib import Path
import stat
import signal
import subprocess
import tempfile
import unittest
from unittest import mock

REPO=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('checkout_policy_upgrade_subject',REPO/'deployment/upgrade_go_erp_checkout_policy_root.py')
subject=importlib.util.module_from_spec(spec);spec.loader.exec_module(subject)

@unittest.skipUnless(os.geteuid()==0,'Private runtime fixture requires root.')
class CheckoutPolicyUpgradeTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='go-erp-runtime-upgrade-')
        self.addCleanup(self.tmp.cleanup);self.base=Path(self.tmp.name)
        self.live=self.base/'live';self.live.mkdir(mode=0o750)
        self.work=self.base/'work';self.work.mkdir(mode=0o700)
        (self.work/'state').mkdir(mode=0o700);(self.work/'checkout').mkdir(mode=0o755)
        self.lock=self.work/'watcher.lock';self.lock.write_bytes(b'');self.lock.chmod(0o600)
        self.state=self.work/'state/current.json'
        self.state.write_text(json.dumps({'format':1,'base_revision':subject.BASE,'counter':19,
            'observed':{'inventory':{}},'desired':{'status':'waiting-ci','version':'0.4.4-preview.19','pushed':True,
                'commit':'f53ef1e6b1f99d15cca7e0ce9b0ac8ab5648b6dc',
                'candidatePolicy':'historical-baseline-v1','retry_at':12345},
            'last_remote_sha':'f53ef1e6b1f99d15cca7e0ce9b0ac8ab5648b6dc',
            'published':{'version':'0.4.4-preview.11'},'dependency_guard':{'fixture':'fixed'},
            'sequence_floor':101,'version_floor':'0.4.4-preview.11','published_history':['UNCHANGED_STATE']}))
        self.state.chmod(0o600)
        config_parent=self.base/'config';config_parent.mkdir(mode=0o700)
        self.config=config_parent/'live-updates.json';self.config.write_bytes(b'PRIVATE_TOKEN_SENTINEL\n')
        self.config.chmod(0o600)
        unit_parent=self.base/'systemd';unit_parent.mkdir(mode=0o755)
        self.unit=unit_parent/'go-erp-live-updates.service'
        self.releases=self.base/'releases';self.releases.mkdir(mode=0o755)
        self.old=self.releases/subject.OLD_RUNTIME_NAME;self.old.mkdir(mode=0o755)
        old_source=b'# original source compiler\n';old_worker=b'# original worker\n';publisher=b'# unchanged publisher\n'
        old_payloads={'go_erp_live_source.py':old_source,'go_erp_live_updates.py':old_worker,
                      'publish_go_erp_update.py':publisher,
                      'go_erp_current_candidate_ci.yml':b'name: Old v1 checkout policy\n',
                      'verify_go_erp_current_candidate_ci.py':b'# old v1 verifier\n'}
        self.old_pins={name:subject.blob(data) for name,data in old_payloads.items()}
        for name,data in old_payloads.items():(self.old/name).write_bytes(data);(self.old/name).chmod(0o644)
        self.unit.write_bytes(subject.service_unit(self.old));self.unit.chmod(0o644)
        self.unit_original=self.unit.stat();self.unit_bytes=self.unit.read_bytes()
        self.payloads={
            'go_erp_live_source.py':("CANDIDATE_POLICY="+repr(subject.POLICY)+"\n"
                "def scan_snapshot(live,seed,uid,previous):\n"
                "    if uid!=1001:raise ValueError()\n"
                "    return {'metadata':{'inventory':{'fixture':{}}}}\n").encode(),
            'go_erp_live_updates.py':("CANDIDATE_POLICY="+repr(subject.POLICY)+"\n").encode()+b"import json\ndef parse_json(data):return json.loads(data)\ndef dependency_snapshot(live,uid):return {'fixture':'fixed'}\nclass Worker:\n    def __init__(self,*args,**kwargs):raise AssertionError('Worker must never be constructed')\ndef main(*args):raise AssertionError('Worker main must never run')\ndef read_config(*args):raise AssertionError('Credentials must never be read')\n",
            'publish_go_erp_update.py':publisher,
            'go_erp_current_candidate_ci.yml':b'name: Reviewed current candidate CI\n',
            'verify_go_erp_current_candidate_ci.py':b'# reviewed CI validator\n'}
        self.pins={name:subject.blob(data) for name,data in self.payloads.items()}
        self.commands=[];self.unit_checks=[];self.secret_before=self.private_snapshot();self.old_snapshot=self.tree(self.old)
    def tree(self,path):
        return {str(item.relative_to(path)):(subject.key(item.stat()),item.read_bytes())
                for item in path.rglob('*') if item.is_file()}
    def private_snapshot(self):
        return {str(path):(subject.exact_key(path.stat()),hashlib.sha256(path.read_bytes()).hexdigest())
                for path in (self.config,self.state)}
    def command(self,arguments):
        self.commands.append(arguments)
        if arguments[:2]==['/usr/bin/systemd-analyze','verify']:
            path=Path(arguments[2]);self.assertTrue(path.exists());self.assertEqual(path.suffix,'.service')
            self.assertEqual(path.stat().st_mode&0o777,0o644)
    def upgrade(self,**kwargs):
        kwargs.setdefault('command',self.command)
        kwargs.setdefault('verify_unit',self.verify_unit)
        return subject.upgrade_runtime(self.payloads,self.pins,self.old_pins,live=self.live,
            work=self.work,releases=self.releases,unit=self.unit,config=self.config,**kwargs)
    def verify_unit(self,unit,config,runtimes,allow_pending_reload=False):
        self.assertEqual(unit,self.unit);self.assertEqual(config,self.config)
        self.unit_checks.append((tuple(runtimes),allow_pending_reload))
    def assert_private_unchanged(self):
        self.assertEqual(self.private_snapshot(),self.secret_before)
        self.assertEqual(self.tree(self.old),self.old_snapshot)
    def target(self):return self.releases/subject.runtime_name(self.payloads)
    def audits(self):return list(self.work.glob('checkout-policy-upgrade-*'))
    def test_current_v1_to_v2_keeps_pushed_19_candidate_and_every_private_byte(self):
        report=self.upgrade();self.assertTrue(report['ok'],report)
        self.assertTrue(report['unit_changed']);self.assertEqual(report['counter'],19)
        self.assertTrue(report['desired']);self.assertTrue(report['published']);self.assertEqual(report['source_files'],1)
        self.assertFalse(report['configuration_read']);self.assertFalse(report['state_changed'])
        self.assertEqual(self.unit.read_bytes(),subject.service_unit(self.target()))
        expected=self.unit_bytes.replace(str(self.old).encode(),str(self.target()).encode())
        self.assertEqual(self.unit.read_bytes(),expected)
        self.assert_private_unchanged()
        audit=self.audits()[0];self.assertEqual((audit/'exchange-unit.service').stat().st_ino,self.unit_original.st_ino)
        self.assertEqual((audit/'before-unit.bin').read_bytes(),self.unit_bytes)
        self.assertEqual((audit/'before-unit.bin').stat().st_mode&0o777,0o600)
        self.assertEqual(set(path.name for path in self.target().iterdir()),set(subject.ROSTER))
        for path in self.target().iterdir():
            self.assertEqual(path.stat().st_mode&0o777,0o644);self.assertEqual(path.stat().st_nlink,1)
        self.assertEqual(self.commands[-1],['/usr/bin/systemctl','daemon-reload'])
        self.assertEqual(self.unit_checks,[((self.old,),False),((self.target(),),False)])
        saved=json.loads(self.state.read_text())
        self.assertEqual(saved['desired']['candidatePolicy'],'historical-baseline-v1')
        self.assertEqual(saved['desired']['version'],'0.4.4-preview.19')
        self.assertTrue(saved['desired']['pushed'])
        self.assertEqual(saved['last_remote_sha'],saved['desired']['commit'])
    def test_idempotent_target_and_unit_do_not_create_new_audit(self):
        self.assertTrue(self.upgrade()['ok']);audits=self.audits();inode=self.unit.stat().st_ino
        report=self.upgrade();self.assertTrue(report['ok']);self.assertTrue(report['already_current'])
        self.assertFalse(report['unit_changed']);self.assertEqual(self.unit.stat().st_ino,inode)
        self.assertEqual(self.audits(),audits);self.assert_private_unchanged()
    def test_rerun_reloads_disk_unit_even_if_previous_process_died_before_reload(self):
        self.assertTrue(self.upgrade()['ok']);self.commands=[];self.unit_checks=[]
        report=self.upgrade();self.assertTrue(report['ok']);self.assertTrue(report['already_current'])
        self.assertEqual(self.commands,[['/usr/bin/systemctl','daemon-reload']])
        self.assertEqual(self.unit_checks,[((self.old,self.target()),True),((self.target(),),False)])
        self.assert_private_unchanged()
    def test_unreviewed_effective_unit_stops_before_runtime_install(self):
        def reject(*args,**kwargs):raise subject.Stop('EFFECTIVE_UNIT_UNREVIEWED')
        report=self.upgrade(verify_unit=reject)
        self.assertFalse(report['ok']);self.assertEqual(report['reason'],'EFFECTIVE_UNIT_UNREVIEWED')
        self.assertFalse(self.target().exists());self.assertEqual(self.unit.read_bytes(),self.unit_bytes)
        self.assert_private_unchanged()
    def test_failed_effective_reload_verification_restores_original_unit(self):
        checks=0
        def verify(*args,**kwargs):
            nonlocal checks
            checks+=1;self.verify_unit(*args,**kwargs)
            if checks==2:raise subject.Stop('EFFECTIVE_RUNTIME_UNREVIEWED')
        report=self.upgrade(verify_unit=verify)
        self.assertFalse(report['ok']);self.assertTrue(report['rollback_complete'])
        self.assertEqual(report['reason'],'EFFECTIVE_RUNTIME_UNREVIEWED')
        self.assertEqual(checks,3);self.assertFalse(report['unit_changed'])
        self.assertEqual(self.unit.read_bytes(),self.unit_bytes);self.assert_private_unchanged()
    def test_predecessor_roster_is_exactly_five_files(self):
        (self.old/'unreviewed.py').write_bytes(b'# refuse extra runtime code\n')
        (self.old/'unreviewed.py').chmod(0o644)
        report=self.upgrade();self.assertFalse(report['ok'])
        self.assertEqual(report['reason'],'RUNTIME_ROSTER_INVALID')
        self.assertFalse(self.target().exists());self.assertEqual(self.unit.read_bytes(),self.unit_bytes)
    def test_new_worker_and_compiler_policy_must_agree(self):
        name='go_erp_live_updates.py'
        self.payloads[name]=self.payloads[name].replace(b'historical-baseline-v2',b'historical-baseline-v1')
        self.pins[name]=subject.blob(self.payloads[name])
        report=self.upgrade();self.assertFalse(report['ok'])
        self.assertEqual(report['reason'],'POLICY_PROTOCOL_INVALID')
        self.assertEqual(self.unit.read_bytes(),self.unit_bytes);self.assert_private_unchanged()
    def test_config_open_is_metadata_only_and_never_read(self):
        original_open,original_read=os.open,os.read;identity=(self.config.stat().st_dev,self.config.stat().st_ino)
        flags=[]
        def opened(path,value,*args,**kwargs):
            fd=original_open(path,value,*args,**kwargs);item=os.fstat(fd)
            if (item.st_dev,item.st_ino)==identity:flags.append(value);self.assertTrue(value&os.O_PATH)
            return fd
        def read(fd,count):
            item=os.fstat(fd);self.assertNotEqual((item.st_dev,item.st_ino),identity)
            return original_read(fd,count)
        with mock.patch.object(os,'open',opened),mock.patch.object(os,'read',read):report=self.upgrade()
        self.assertTrue(report['ok']);self.assertEqual(len(flags),1);self.assert_private_unchanged()
    def test_edited_old_unit_is_not_replaced(self):
        self.unit.write_bytes(self.unit_bytes+b'# unreviewed\n');before=self.unit.read_bytes()
        report=self.upgrade();self.assertFalse(report['ok']);self.assertEqual(self.unit.read_bytes(),before)
        self.assertEqual(report['reason'],'SERVICE_UNIT_UNREVIEWED');self.assertEqual(self.audits(),[])
        self.assert_private_unchanged()
    def test_wrong_old_runtime_pin_stops_before_install(self):
        (self.old/'go_erp_live_source.py').write_bytes(b'# changed old source\n')
        report=self.upgrade();self.assertFalse(report['ok']);self.assertEqual(report['reason'],'RUNTIME_PIN_INVALID')
        self.assertFalse(self.target().exists());self.assertEqual(self.unit.read_bytes(),self.unit_bytes)
    def test_wrong_payload_pin_or_extra_roster_never_changes_unit(self):
        self.payloads['go_erp_live_source.py']+=b'# tampered\n'
        report=self.upgrade();self.assertFalse(report['ok']);self.assertEqual(report['reason'],'PAYLOAD_PIN_INVALID')
        self.assertEqual(self.unit.read_bytes(),self.unit_bytes);self.assert_private_unchanged()
        self.payloads['unexpected.py']=b'# extra\n'
        report=self.upgrade();self.assertFalse(report['ok']);self.assertEqual(report['reason'],'PAYLOAD_SCOPE_INVALID')
    def test_unreviewed_existing_target_is_never_overwritten(self):
        self.target().mkdir(mode=0o755);foreign=self.target()/'FOREIGN';foreign.write_bytes(b'preserve')
        report=self.upgrade();self.assertFalse(report['ok']);self.assertEqual(foreign.read_bytes(),b'preserve')
        self.assertEqual(self.unit.read_bytes(),self.unit_bytes);self.assert_private_unchanged()
    def test_payload_symlink_and_untrusted_owner_are_refused(self):
        folder=self.base/'payload';folder.mkdir(mode=0o700)
        for name,data in self.payloads.items():(folder/name).write_bytes(data);(folder/name).chmod(0o600)
        path=folder/'go_erp_live_source.py';path.unlink();path.symlink_to(self.config)
        with mock.patch.object(subject.socket,'gethostname',return_value='srv1836524'):
            with self.assertRaises(OSError):subject.execute([str(folder),'--upgrade'])
        self.assert_private_unchanged()
    def test_config_symlink_and_bad_modes_refuse_before_unit_switch(self):
        original=self.config.read_bytes();self.config.unlink();self.config.symlink_to(self.state)
        report=self.upgrade();self.assertFalse(report['ok']);self.assertEqual(self.unit.read_bytes(),self.unit_bytes)
        self.config.unlink();self.config.write_bytes(original);self.config.chmod(0o644)
        report=self.upgrade();self.assertFalse(report['ok']);self.assertEqual(report['reason'],'CONFIGURATION_METADATA_INVALID')
        self.assertEqual(self.unit.read_bytes(),self.unit_bytes)
    def test_unit_symlink_and_hardlink_refuse(self):
        self.unit.unlink();self.unit.symlink_to(self.state)
        report=self.upgrade();self.assertFalse(report['ok']);self.assertTrue(self.unit.is_symlink())
        self.unit.unlink();self.unit.write_bytes(self.unit_bytes);self.unit.chmod(0o644)
        os.link(self.unit,self.base/'linked-unit')
        report=self.upgrade();self.assertFalse(report['ok']);self.assertEqual(self.unit.read_bytes(),self.unit_bytes)
        self.assert_private_unchanged()
    def test_busy_watcher_never_changes_unit(self):
        fd=os.open(self.lock,os.O_RDONLY)
        try:
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            report=self.upgrade();self.assertFalse(report['ok']);self.assertEqual(report['reason'],'WATCHER_BUSY')
        finally:os.close(fd)
        self.assertEqual(self.unit.read_bytes(),self.unit_bytes);self.assert_private_unchanged()
    def test_preflight_probe_failure_retains_runtime_but_not_switch_unit(self):
        def fail(*args):raise subject.Stop('TEST_PROBE_FAILURE')
        report=self.upgrade(probe=fail);self.assertFalse(report['ok']);self.assertTrue(self.target().exists())
        self.assertEqual(self.unit.read_bytes(),self.unit_bytes);self.assertEqual(self.audits(),[])
        self.assert_private_unchanged()
    def test_daemon_reload_failure_restores_original_inode_and_reloads(self):
        calls=0
        def command(args):
            nonlocal calls
            self.command(args)
            if args==['/usr/bin/systemctl','daemon-reload']:
                calls+=1
                if calls==1:raise subject.Stop('SYSTEMD_COMMAND_FAILED')
        report=self.upgrade(command=command);self.assertFalse(report['ok']);self.assertTrue(report['rollback_complete'])
        self.assertFalse(report['unit_changed']);self.assertEqual(calls,2)
        self.assertEqual(subject.key(self.unit.stat()),subject.key(self.unit_original));self.assert_private_unchanged()
    def test_postflight_probe_failure_restores_unit_and_private_data(self):
        calls=0
        def probe(*args):
            nonlocal calls
            calls+=1
            if calls==2:raise subject.Stop('TEST_POSTFLIGHT_FAILURE')
            return subject.probe_runtime(*args)
        report=self.upgrade(probe=probe);self.assertFalse(report['ok']);self.assertTrue(report['rollback_complete'])
        self.assertEqual(subject.key(self.unit.stat()),subject.key(self.unit_original));self.assert_private_unchanged()
    def test_interruption_after_exchange_restores_unit_under_watcher_lock(self):
        original=subject.exchange;count=0
        def interrupted(*args):
            nonlocal count
            count+=1;original(*args)
            if count==1:raise KeyboardInterrupt()
        with mock.patch.object(subject,'exchange',interrupted):report=self.upgrade()
        self.assertFalse(report['ok']);self.assertEqual(report['reason'],'INTERRUPTED')
        self.assertTrue(report['rollback_complete']);self.assertFalse(report['unit_changed'])
        self.assertEqual(subject.key(self.unit.stat()),subject.key(self.unit_original));self.assert_private_unchanged()
    def test_interruption_after_reload_also_restores_unit(self):
        calls=0
        def command(args):
            nonlocal calls
            self.command(args)
            if args==['/usr/bin/systemctl','daemon-reload']:
                calls+=1
                if calls==1:raise KeyboardInterrupt()
        report=self.upgrade(command=command);self.assertFalse(report['ok']);self.assertTrue(report['rollback_complete'])
        self.assertEqual(calls,2);self.assertEqual(self.unit.read_bytes(),self.unit_bytes);self.assert_private_unchanged()
    def test_foreign_unit_race_is_retained_and_not_deleted(self):
        foreign=self.base/'foreign-unit';foreign.write_bytes(b'concurrent root unit\n');foreign.chmod(0o644)
        inode=foreign.stat().st_ino
        def race():os.replace(foreign,self.unit)
        report=self.upgrade(before_exchange=race);self.assertFalse(report['ok'])
        self.assertFalse(report['rollback_complete']);self.assertEqual(self.unit.read_bytes(),b'concurrent root unit\n')
        self.assertEqual(self.unit.stat().st_ino,inode);self.assert_private_unchanged()
    def test_state_drift_is_not_rewritten_and_rolls_back_unit(self):
        calls=0
        def command(args):
            nonlocal calls
            self.command(args)
            if args==['/usr/bin/systemctl','daemon-reload']:
                calls+=1
                if calls==1:self.state.write_bytes(b'{"concurrent":"state"}\n')
        report=self.upgrade(command=command);self.assertFalse(report['ok']);self.assertTrue(report['rollback_complete'])
        self.assertEqual(self.state.read_bytes(),b'{"concurrent":"state"}\n')
        self.assertEqual(self.config.read_bytes(),b'PRIVATE_TOKEN_SENTINEL\n')
        self.assertEqual(self.unit.read_bytes(),self.unit_bytes)
    def test_unapproved_module_owner_refused(self):
        target=(self.old/'go_erp_live_source.py').stat();original=os.fstat
        class View:
            st_uid=1001
            def __init__(self,value):self.value=value
            def __getattr__(self,name):return getattr(self.value,name)
        def fstat(fd):
            item=original(fd)
            return View(item) if (item.st_dev,item.st_ino)==(target.st_dev,target.st_ino) else item
        with mock.patch.object(os,'fstat',fstat):report=self.upgrade()
        self.assertFalse(report['ok']);self.assertEqual(report['reason'],'FILE_UNSAFE')
        self.assertEqual(self.unit.read_bytes(),self.unit_bytes);self.assert_private_unchanged()
    def test_cli_fixed_root_and_host_refuse(self):
        with mock.patch.object(subject.socket,'gethostname',return_value='wrong-host'):
            with self.assertRaises(subject.Stop):subject.execute(['payload','--upgrade'])
        with self.assertRaises(subject.Stop):subject.execute([])

class CheckoutPolicyPayloadBindingTests(unittest.TestCase):
    def test_new_runtime_pins_match_all_five_current_release_files(self):
        self.assertEqual(set(subject.PINS),set(subject.ROSTER))
        for name,pin in subject.PINS.items():
            self.assertEqual(subject.blob((REPO/'deployment'/name).read_bytes()),pin,name)
        self.assertEqual(subject.PINS['publish_go_erp_update.py'],subject.OLD_PINS['publish_go_erp_update.py'])
    def test_predecessor_pins_match_all_five_immutable_v1_release_files(self):
        self.assertEqual(set(subject.OLD_PINS),set(subject.ROSTER))
        for name,pin in subject.OLD_PINS.items():
            raw=subprocess.check_output(['git','-c','safe.directory='+str(REPO),'-C',str(REPO),
                'show','6dcc70f8fa78ee9ab93ea8acf08cb405bb690216:deployment/'+name])
            self.assertEqual(subject.blob(raw),pin,name)
    def test_entrypoint_pin_matches_the_exact_upgrade_helper(self):
        wrapper=(REPO/'deployment/upgrade_go_erp_checkout_policy_root.sh').read_text()
        pin=next(line.split('=',1)[1] for line in wrapper.splitlines() if line.startswith('go_helper='))
        self.assertEqual(pin,subject.blob((REPO/'deployment/upgrade_go_erp_checkout_policy_root.py').read_bytes()))
    def test_entrypoint_handles_hangup_without_private_output_and_restores_handler(self):
        before=signal.getsignal(signal.SIGHUP);output=io.StringIO()
        def hangup(_args):signal.raise_signal(signal.SIGHUP)
        with mock.patch.object(subject,'execute',hangup),contextlib.redirect_stdout(output):
            result=subject.main([])
        self.assertEqual(result,1);report=json.loads(output.getvalue())
        self.assertFalse(report['ok']);self.assertEqual(report['reason'],'INTERRUPTED')
        self.assertIs(signal.getsignal(signal.SIGHUP),before)


class EffectiveUnitTests(unittest.TestCase):
    def properties(self,runtime):
        command='/usr/bin/python3 -I -B '+str(runtime/'go_erp_live_updates.py')
        command+=' --config '+str(subject.CONFIG)+' --once'
        return {'FragmentPath':str(subject.UNIT),'DropInPaths':'','NeedDaemonReload':'no',
            'ActiveState':'inactive','ExecStart':'{ path=/usr/bin/python3 ; argv[]='+command
                +' ; ignore_errors=no ; }'}
    def validate(self,properties,allowed=None,pending=False):
        old=subject.RELEASES/subject.OLD_RUNTIME_NAME
        subject.validate_effective_unit(properties,subject.UNIT,subject.CONFIG,
            (old,) if allowed is None else allowed,allow_pending_reload=pending)
    def test_exact_inactive_v1_unit_is_accepted(self):
        self.validate(self.properties(subject.RELEASES/subject.OLD_RUNTIME_NAME))
    def test_unreviewed_runtime_and_multiple_commands_are_refused(self):
        old=subject.RELEASES/subject.OLD_RUNTIME_NAME
        with self.assertRaisesRegex(subject.Stop,'EFFECTIVE_RUNTIME_UNREVIEWED'):
            self.validate(self.properties(subject.RELEASES/'foreign'))
        properties=self.properties(old);properties['ExecStart']+=' '+properties['ExecStart']
        with self.assertRaisesRegex(subject.Stop,'EFFECTIVE_RUNTIME_UNREVIEWED'):
            self.validate(properties)
    def test_drop_in_and_unexpected_fragment_are_refused(self):
        for name,value in [('DropInPaths','/etc/systemd/system/override.conf'),
                           ('FragmentPath','/tmp/unreviewed.service')]:
            properties=self.properties(subject.RELEASES/subject.OLD_RUNTIME_NAME)
            properties[name]=value
            with self.subTest(name=name):
                with self.assertRaisesRegex(subject.Stop,'EFFECTIVE_UNIT_UNREVIEWED'):
                    self.validate(properties)
    def test_stale_loaded_unit_is_allowed_only_for_interrupted_reload_recovery(self):
        old=subject.RELEASES/subject.OLD_RUNTIME_NAME;target=subject.RELEASES/'policy-new'
        properties=self.properties(old);properties['NeedDaemonReload']='yes'
        with self.assertRaisesRegex(subject.Stop,'EFFECTIVE_UNIT_RELOAD_REQUIRED'):
            self.validate(properties)
        self.validate(properties,(old,target),pending=True)
        with self.assertRaisesRegex(subject.Stop,'EFFECTIVE_RUNTIME_UNREVIEWED'):
            self.validate(properties,(target,),pending=True)
    def test_running_service_is_refused(self):
        properties=self.properties(subject.RELEASES/subject.OLD_RUNTIME_NAME)
        properties['ActiveState']='activating'
        with self.assertRaisesRegex(subject.Stop,'WATCHER_BUSY'):self.validate(properties)

if __name__=='__main__':unittest.main()
