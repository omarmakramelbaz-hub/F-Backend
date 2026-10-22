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
import tempfile
import unittest
from unittest import mock

REPO=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('runtime_upgrade_subject',REPO/'deployment/upgrade_go_erp_live_runtime_root.py')
subject=importlib.util.module_from_spec(spec);spec.loader.exec_module(subject)

@unittest.skipUnless(os.geteuid()==0,'Private runtime fixture requires root.')
class RuntimeUpgradeTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='go-erp-runtime-upgrade-')
        self.addCleanup(self.tmp.cleanup);self.base=Path(self.tmp.name)
        self.live=self.base/'live';self.live.mkdir(mode=0o750)
        self.work=self.base/'work';self.work.mkdir(mode=0o700)
        (self.work/'state').mkdir(mode=0o700);(self.work/'checkout').mkdir(mode=0o755)
        self.lock=self.work/'watcher.lock';self.lock.write_bytes(b'');self.lock.chmod(0o600)
        self.state=self.work/'state/current.json'
        self.state.write_text(json.dumps({'format':1,'base_revision':subject.BASE,'counter':18,
            'observed':{'inventory':{}},'desired':{'status':'blocked','version':'0.4.4-preview.18'},
            'published':{'version':'0.4.4-preview.11'},'dependency_guard':{'fixture':'fixed'},
            'sequence_floor':101,'version_floor':'0.4.4-preview.11','published_history':['UNCHANGED_STATE']}))
        self.state.chmod(0o600)
        config_parent=self.base/'config';config_parent.mkdir(mode=0o700)
        self.config=config_parent/'live-updates.json';self.config.write_bytes(b'PRIVATE_TOKEN_SENTINEL\n')
        self.config.chmod(0o600)
        unit_parent=self.base/'systemd';unit_parent.mkdir(mode=0o755)
        self.unit=unit_parent/'go-erp-live-updates.service'
        self.releases=self.base/'releases';self.releases.mkdir(mode=0o755)
        self.old=self.releases/subject.BASE;self.old.mkdir(mode=0o755)
        old_source=b'# original source compiler\n';old_worker=b'# original worker\n';publisher=b'# unchanged publisher\n'
        old_payloads={'go_erp_live_source.py':old_source,'go_erp_live_updates.py':old_worker,
                      'publish_go_erp_update.py':publisher}
        self.old_pins={name:subject.blob(data) for name,data in old_payloads.items()}
        for name,data in old_payloads.items():(self.old/name).write_bytes(data);(self.old/name).chmod(0o644)
        (self.old/'legacy-unused-helper.py').write_bytes(b'# preserved legacy helper\n')
        (self.old/'legacy-unused-helper.py').chmod(0o644)
        self.unit.write_bytes(subject.service_unit(self.old));self.unit.chmod(0o644)
        self.unit_original=self.unit.stat();self.unit_bytes=self.unit.read_bytes()
        self.payloads={
            'go_erp_live_source.py':("CANDIDATE_POLICY="+repr(subject.POLICY)+"\n"
                "def scan_snapshot(live,seed,uid,previous):\n"
                "    if uid!=1001:raise ValueError()\n"
                "    return {'metadata':{'inventory':{'fixture':{}}}}\n").encode(),
            'go_erp_live_updates.py':b"import json\ndef parse_json(data):return json.loads(data)\ndef dependency_snapshot(live,uid):return {'fixture':'fixed'}\nclass Worker:\n    def __init__(self,*args,**kwargs):raise AssertionError('Worker must never be constructed')\ndef main(*args):raise AssertionError('Worker main must never run')\ndef read_config(*args):raise AssertionError('Credentials must never be read')\n",
            'publish_go_erp_update.py':publisher,
            'go_erp_current_candidate_ci.yml':b'name: Reviewed current candidate CI\n',
            'verify_go_erp_current_candidate_ci.py':b'# reviewed CI validator\n'}
        self.pins={name:subject.blob(data) for name,data in self.payloads.items()}
        self.commands=[];self.secret_before=self.private_snapshot();self.old_snapshot=self.tree(self.old)
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
        return subject.upgrade_runtime(self.payloads,self.pins,self.old_pins,live=self.live,
            work=self.work,releases=self.releases,unit=self.unit,config=self.config,**kwargs)
    def assert_private_unchanged(self):
        self.assertEqual(self.private_snapshot(),self.secret_before)
        self.assertEqual(self.tree(self.old),self.old_snapshot)
    def target(self):return self.releases/subject.runtime_name(self.payloads)
    def audits(self):return list(self.work.glob('runtime-upgrade-*'))
    def test_switches_only_execstart_and_keeps_every_private_byte(self):
        report=self.upgrade();self.assertTrue(report['ok'],report)
        self.assertTrue(report['unit_changed']);self.assertEqual(report['counter'],18)
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
    def test_idempotent_target_and_unit_do_not_create_new_audit(self):
        self.assertTrue(self.upgrade()['ok']);audits=self.audits();inode=self.unit.stat().st_ino
        report=self.upgrade();self.assertTrue(report['ok']);self.assertTrue(report['already_current'])
        self.assertFalse(report['unit_changed']);self.assertEqual(self.unit.stat().st_ino,inode)
        self.assertEqual(self.audits(),audits);self.assert_private_unchanged()
    def test_rerun_reloads_disk_unit_even_if_previous_process_died_before_reload(self):
        self.assertTrue(self.upgrade()['ok']);self.commands=[]
        report=self.upgrade();self.assertTrue(report['ok']);self.assertTrue(report['already_current'])
        self.assertEqual(self.commands,[['/usr/bin/systemctl','daemon-reload']])
        self.assert_private_unchanged()
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

class RuntimePayloadBindingTests(unittest.TestCase):
    def test_final_runtime_pins_match_all_five_reviewed_release_files(self):
        self.assertEqual(set(subject.PINS),set(subject.ROSTER))
        for name,pin in subject.PINS.items():
            self.assertEqual(subject.blob((REPO/'deployment'/name).read_bytes()),pin,name)
        self.assertEqual(subject.PINS['publish_go_erp_update.py'],subject.OLD_PINS['publish_go_erp_update.py'])
    def test_entrypoint_pin_matches_the_exact_upgrade_helper(self):
        wrapper=(REPO/'deployment/upgrade_go_erp_live_runtime_root.sh').read_text()
        pin=next(line.split('=',1)[1] for line in wrapper.splitlines() if line.startswith('go_helper='))
        self.assertEqual(pin,subject.blob((REPO/'deployment/upgrade_go_erp_live_runtime_root.py').read_bytes()))
    def test_entrypoint_handles_hangup_without_private_output_and_restores_handler(self):
        before=signal.getsignal(signal.SIGHUP);output=io.StringIO()
        def hangup(_args):signal.raise_signal(signal.SIGHUP)
        with mock.patch.object(subject,'execute',hangup),contextlib.redirect_stdout(output):
            result=subject.main([])
        self.assertEqual(result,1);report=json.loads(output.getvalue())
        self.assertFalse(report['ok']);self.assertEqual(report['reason'],'INTERRUPTED')
        self.assertIs(signal.getsignal(signal.SIGHUP),before)

if __name__=='__main__':unittest.main()
