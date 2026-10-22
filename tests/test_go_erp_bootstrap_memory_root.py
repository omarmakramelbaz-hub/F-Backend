#!/usr/bin/env python3
"""Exact two-file deployment, rollback, source projection and private recovery tests."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest import mock

REPO = Path(__file__).resolve().parents[1]

def load(name,file):
    spec = importlib.util.spec_from_file_location(name,REPO/'deployment'/file)
    value = importlib.util.module_from_spec(spec);spec.loader.exec_module(value)
    return value

subject = load('memory_patch_subject','apply_go_erp_bootstrap_memory_root.py')
ops = load('memory_patch_ops','finalize_go_erp_publication_links_root.py').__dict__

class VirtualOwnership:
    def __init__(self):
        self.values = {};self.stat,self.fstat,self.lstat = os.stat,os.fstat,os.lstat
    def view(self,item):
        pair = self.values.get((item.st_dev,item.st_ino))
        if pair is None:return item
        class View:
            st_uid,st_gid = pair
            def __getattr__(self,name):return getattr(item,name)
        return View()
    def chown(self,path,uid,gid,**kwargs):
        item = self.stat(path,**kwargs);self.values[(item.st_dev,item.st_ino)] = (uid,gid)
    def fchown(self,fd,uid,gid):
        item = self.fstat(fd);self.values[(item.st_dev,item.st_ino)] = (uid,gid)
    def patches(self):
        return [mock.patch.object(os,'stat',lambda *a,**k:self.view(self.stat(*a,**k))),
                mock.patch.object(os,'lstat',lambda *a,**k:self.view(self.lstat(*a,**k))),
                mock.patch.object(os,'fstat',lambda *a,**k:self.view(self.fstat(*a,**k))),
                mock.patch.object(os,'chown',self.chown),mock.patch.object(os,'fchown',self.fchown)]

class Source:
    def __init__(self):
        self.hook = None
        self.calls = 0
    def _metadata(self,seed,inventory):
        return {'seedRevision':seed,'inventory':inventory}
    def scan_snapshot(self,root,seed,uid,previous):
        self.calls += 1
        if self.hook:
            self.hook(self)
        inventory = {name:dict(row) for name,row in previous['inventory'].items()}
        for path in sorted(root.rglob('*')):
            if path.is_file():
                name = path.relative_to(root).as_posix();data = path.read_bytes()
                inventory[name] = {'authority':'live','present':True,'sha256':subject.sha(data),
                    'bytes':len(data),'mode':'100755' if path.stat().st_mode & 0o111 else '100644'}
        return {'metadata':self._metadata('fixed-seed',inventory)}

@unittest.skipUnless(os.geteuid() == 0,'Private deployment fixtures require root.')
class MemoryPatchTests(unittest.TestCase):
    def setUp(self):
        mapping = Path('/proc/self/uid_map').read_text().split()
        if len(mapping) == 3 and mapping[-1] == '1':
            if os.environ.get('GO_ERP_TEST_REQUIRE_REAL_CHOWN') == '1':
                self.fail('CI requires real ownership; namespace maps UID0 only.')
            adapter = VirtualOwnership()
            for patch in adapter.patches():patch.start();self.addCleanup(patch.stop)
        self.tmp = tempfile.TemporaryDirectory(prefix='go-erp-memory-patch-')
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.root = self.base/'app';self.root.mkdir(mode=0o750)
        self.work = self.base/'work';self.work.mkdir(mode=0o700)
        self.before = {};self.after = {};self.pins = {};self.stats = {}
        for index,name in enumerate(subject.TARGETS):
            path = self.root/name;path.parent.mkdir(parents=True,exist_ok=True)
            self.before[name] = ('<?php\n// before '+str(index)+'\n').encode()
            self.after[name] = ('<?php\n// after '+str(index)+'\n').encode()
            self.pins[name] = (subject.sha(self.before[name]),subject.sha(self.before[name].replace(b'\n',b'\r\n')))
            path.write_bytes(self.before[name]);path.chmod(0o640 if index == 0 else 0o755)
            self.stats[name] = path.stat()
        self.unrelated = self.root/'app/Unrelated.php'
        self.unrelated.write_bytes(b'<?php // unrelated\n');self.unrelated.chmod(0o644)
        self.previous = {'seedRevision':'fixed-seed','inventory':{
            'public/smtp_test.php':{'authority':'removed','present':False,'sha256':None,'bytes':0,'mode':'100644'}}}
        self.source = Source();self.guards = 0;self.linted = []
    def guard(self):
        self.guards += 1
    def patch(self,**kwargs):
        return subject.patch_files(self.root,self.work,self.after,self.pins,self.source,
            self.previous,ops,self.guard,lint=lambda data:self.linted.append(data),**kwargs)
    def live(self):
        return {name:(self.root/name).read_bytes() for name in subject.TARGETS}
    def audits(self):
        return list(self.work.glob('bootstrap-memory-*'))
    def assert_original(self):
        self.assertEqual(self.live(),self.before)
        for name,value in self.stats.items():
            self.assertEqual(subject.key((self.root/name).stat()),subject.key(value))
    def test_exact_pair_atomic_inodes_private_backup_and_mode_preservation(self):
        report = self.patch();self.assertTrue(report['ok']);self.assertEqual(report['changed'],2)
        self.assertEqual(self.live(),self.after);self.assertGreater(self.guards,0)
        audit = self.audits()[0];self.assertEqual(stat.S_IMODE(audit.stat().st_mode),0o700)
        for index,name in enumerate(subject.TARGETS):
            current = (self.root/name).stat()
            for field in ('st_uid','st_gid','st_mode'):
                self.assertEqual(getattr(current,field),getattr(self.stats[name],field))
            original = audit/('exchange-{:04d}.bin'.format(index))
            self.assertEqual(subject.key(original.stat()),subject.key(self.stats[name]))
            copy = audit/('before-{:04d}.bin'.format(index))
            self.assertEqual(copy.read_bytes(),self.before[name]);self.assertEqual(copy.stat().st_mode & 0o777,0o600)
            self.assertNotEqual(copy.stat().st_ino,original.stat().st_ino)
        self.assertEqual(self.unrelated.read_bytes(),b'<?php // unrelated\n')
        self.assertEqual(len(self.linted),2)
    def test_idempotence_has_no_second_audit(self):
        self.assertTrue(self.patch()['ok']);audits = self.audits()
        report = self.patch();self.assertTrue(report['ok']);self.assertEqual(report['already_current'],2)
        self.assertEqual(report['changed'],0);self.assertEqual(audits,self.audits())
    def test_mixed_current_and_before_changes_only_needed_file(self):
        first = subject.TARGETS[0];(self.root/first).write_bytes(self.after[first])
        inode = (self.root/first).stat().st_ino
        report = self.patch();self.assertTrue(report['ok']);self.assertEqual(report['changed'],1)
        self.assertEqual(report['already_current'],1);self.assertEqual(self.live(),self.after)
        self.assertEqual((self.root/first).stat().st_ino,inode)
    def test_crlf_before_produces_only_crlf_after(self):
        for name in subject.TARGETS:
            (self.root/name).write_bytes(self.before[name].replace(b'\n',b'\r\n'))
        report = self.patch();self.assertTrue(report['ok'])
        self.assertEqual(self.live(),{name:data.replace(b'\n',b'\r\n') for name,data in self.after.items()})
    def test_unknown_before_refuses_entire_pair_without_audit(self):
        (self.root/subject.TARGETS[1]).write_bytes(b'<?php // unknown\n');before = self.live()
        report = self.patch();self.assertFalse(report['ok']);self.assertEqual(self.live(),before)
        self.assertEqual(report['reason'],'SOURCE_PIN_INVALID');self.assertEqual(self.audits(),[])
    def test_lint_failure_precedes_all_source_and_audit_changes(self):
        def lint(data):raise subject.Stop('PHP_LINT_FAILED')
        report = subject.patch_files(self.root,self.work,self.after,self.pins,self.source,
            self.previous,ops,self.guard,lint=lint)
        self.assertFalse(report['ok']);self.assert_original();self.assertEqual(self.audits(),[])
    def test_symlink_hardlink_and_world_write_refuse_no_publication(self):
        name = subject.TARGETS[0];path = self.root/name
        for kind in ('symlink','hardlink','world'):
            with self.subTest(kind=kind):
                if kind == 'symlink':
                    path.unlink();path.symlink_to(self.unrelated)
                elif kind == 'hardlink':
                    path.unlink();os.link(self.unrelated,path)
                else:
                    path.unlink();path.write_bytes(self.before[name]);path.chmod(0o666)
                before = (self.root/subject.TARGETS[1]).read_bytes()
                self.assertFalse(self.patch()['ok']);self.assertEqual(self.audits(),[])
                self.assertEqual((self.root/subject.TARGETS[1]).read_bytes(),before)
                path.unlink();path.write_bytes(self.before[name]);path.chmod(0o640)
    def test_unrelated_source_change_is_retained_and_blocks_publication(self):
        def change(row):self.unrelated.write_bytes(b'<?php // concurrent unrelated\n')
        report = self.patch(before_exchange=change);self.assertFalse(report['ok'])
        self.assert_original();self.assertEqual(self.unrelated.read_bytes(),b'<?php // concurrent unrelated\n')
    def test_after_first_exchange_failure_rolls_back_exact_original_inodes(self):
        original_exchange = subject.exchange
        calls = 0
        def interrupted(*args):
            nonlocal calls
            calls += 1;original_exchange(*args)
            if calls == 1:raise OSError('failure after successful syscall')
        with mock.patch.object(subject,'exchange',interrupted):report = self.patch()
        self.assertFalse(report['ok']);self.assertTrue(report['rollback_complete'])
        self.assertFalse(report['application_contents_changed']);self.assertEqual(report['changed'],0)
        self.assert_original()
    def test_failure_after_both_exchanges_rolls_back_both(self):
        def hook(source):
            if all((self.root/name).read_bytes() == self.after[name] for name in subject.TARGETS):
                source.hook = None;raise subject.Stop('TEST_POSTFLIGHT_FAILURE')
        self.source.hook = hook
        report = self.patch();self.assertFalse(report['ok']);self.assertTrue(report['rollback_complete'])
        self.assert_original();self.assertFalse(report['application_contents_changed'])
    def test_competing_target_race_is_captured_and_never_deleted(self):
        original_exchange = subject.exchange;foreign = self.root/'foreign'
        foreign.write_bytes(b'<?php // competing original\n');foreign.chmod(0o640)
        foreign_inode = foreign.stat().st_ino;calls = 0
        def race(left_fd,left,right_fd,right):
            nonlocal calls
            calls += 1
            if calls == 1:
                os.replace(foreign,self.root/subject.TARGETS[0])
            original_exchange(left_fd,left,right_fd,right)
        with mock.patch.object(subject,'exchange',race):report = self.patch()
        self.assertFalse(report['ok']);self.assertFalse(report['rollback_complete'])
        path = self.root/subject.TARGETS[0]
        self.assertEqual(path.stat().st_ino,foreign_inode)
        self.assertEqual(path.read_bytes(),b'<?php // competing original\n')
        self.assertEqual((self.root/subject.TARGETS[1]).read_bytes(),self.before[subject.TARGETS[1]])
    def test_modified_published_inode_is_preserved_and_refuses_rollback(self):
        name = subject.TARGETS[0]
        def hook(source):
            path = self.root/name
            if path.read_bytes() == self.after[name]:
                source.hook = None;path.write_bytes(b'<?php // competing after\n')
        self.source.hook = hook;report = self.patch()
        self.assertFalse(report['ok']);self.assertFalse(report['rollback_complete'])
        self.assertTrue(report['application_contents_changed']);self.assertEqual(report['uncertain'],1)
        self.assertEqual((self.root/name).read_bytes(),b'<?php // competing after\n')
        self.assertEqual((self.audits()[0]/'exchange-0000.bin').stat().st_ino,self.stats[name].st_ino)
    def test_metadata_change_is_refused_even_when_bytes_unchanged(self):
        name = subject.TARGETS[0]
        def mode_change(row):(self.root/name).chmod(0o600)
        report = self.patch(before_exchange=mode_change)
        self.assertFalse(report['ok']);self.assertFalse(report['application_contents_changed'])
        self.assertEqual(self.live(),self.before)
    def test_cli_fails_closed_without_host_or_arguments(self):
        with mock.patch.object(subject.socket,'gethostname',return_value='other-host'):
            with self.assertRaises(subject.Stop):subject.execute(['a','b','c','d','--apply'])
        with self.assertRaises(subject.Stop):subject.execute([])
    def test_actor_ownership_and_group_are_preserved(self):
        for name in subject.TARGETS:
            os.chown(self.root/name,1001,1003);self.stats[name] = (self.root/name).stat()
        report = self.patch();self.assertTrue(report['ok'])
        for name in subject.TARGETS:
            item = (self.root/name).stat();self.assertEqual((item.st_uid,item.st_gid),(1001,1003))
    def test_unapproved_source_owner_refuses_entire_pair(self):
        os.chown(self.root/subject.TARGETS[1],1002,1003)
        report = self.patch();self.assertFalse(report['ok']);self.assertFalse(report['application_contents_changed'])
        self.assertEqual(self.live(),self.before);self.assertEqual(self.audits(),[])
    def test_source_projection_retains_prior_removal_authority(self):
        report = self.patch();self.assertTrue(report['ok'])
        snapshot = self.source.scan_snapshot(self.root,self.work/'checkout',1001,self.previous)['metadata']
        self.assertEqual(snapshot['inventory']['public/smtp_test.php'],self.previous['inventory']['public/smtp_test.php'])


WRAPPER = REPO / 'deployment/apply_go_erp_bootstrap_memory_root.sh'
DOCTOR = '1842302a87b7e93253ad5be616c3ab3e56bdd4a1'
OPS = '795cf1ea467143e8a8ba0d4b6213a0c5ab36e741'
REVISION = '1' * 40


def git_blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


class EntryFixture:
    def __init__(self, active=True, patch_status=0, doctor_status=0,
                 busy=False, restore_fails=False, tamper=False, busy_checks=0,
                 signal_on_sleep=False):
        self.temp = tempfile.TemporaryDirectory(prefix='go-erp-memory-wrapper-test-')
        self.root = Path(self.temp.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.events = self.root / 'events.jsonl'
        self.config = self.root / 'config.json'
        self.operations = self.root / 'operations.jsonl'
        self.config.write_text(json.dumps({'active': active, 'busy': busy,
            'restore_fails': restore_fails, 'tamper': tamper, 'busy_checks':busy_checks,
            'checks':0,'signal_on_sleep':signal_on_sleep}))
        patch = self.script('patch', patch_status)
        doctor = self.script('doctor', doctor_status)
        ops = b'# private filesystem helpers are staged, not executed by Bash\n'
        bootstrap = b'<?php // reviewed bootstrap source fixture\n'
        data = b'<?php // reviewed data source fixture\n'
        self.helper = git_blob(patch)
        blobs = {git_blob(value): value.decode() for value in
                 (patch, doctor, ops, bootstrap, data)}
        self.blobs = self.root / 'blobs.json'
        self.blobs.write_text(json.dumps(blobs))
        pins = {'deployment/apply_go_erp_bootstrap_memory_root.py': self.helper,
                'app/Services/Dashboard/DesktopDashboardBootstrap.php': git_blob(bootstrap),
                'app/Services/Dashboard/DesktopDashboardData.php': git_blob(data)}
        self.pins = self.root / 'pins.json'
        self.pins.write_text(json.dumps(pins))
        text = WRAPPER.read_text()
        # Replace only production endpoints and hard pins in this disposable copy.
        text = text.replace('go_root=/home/fasakha/public_html',
                            'go_root=' + str(self.root / 'live'))
        helper_line = next(line for line in text.splitlines() if line.startswith('go_helper='))
        text = text.replace(helper_line, 'go_helper=' + self.helper)
        text = text.replace(DOCTOR, git_blob(doctor)).replace(OPS, git_blob(ops))
        self.wrapper = self.root / 'apply.sh'
        self.wrapper.write_text(text)
        self.executable('id', '#!/bin/sh\nprintf "0\\n"\n')
        self.executable('hostname', '#!/bin/sh\nprintf "srv1836524\\n"\n')
        self.executable('sleep', r'''#!/usr/bin/python3
import json,os,signal,sys
with open(os.environ['WRAPPER_EVENTS'],'a') as stream:
    stream.write(json.dumps(['sleep',*sys.argv[1:]])+'\n')
config=json.load(open(os.environ['WRAPPER_CONFIG']))
if config['signal_on_sleep']:
    os.kill(os.getppid(),signal.SIGTERM)
''')
        self.executable('runuser', r'''#!/usr/bin/python3
import json,os,sys
args=sys.argv[1:]
with open(os.environ['WRAPPER_EVENTS'],'a') as stream:
    stream.write(json.dumps(['runuser',*args])+'\n')
pins=json.load(open(os.environ['WRAPPER_PINS']))
blobs=json.load(open(os.environ['WRAPPER_BLOBS']))
config=json.load(open(os.environ['WRAPPER_CONFIG']))
if 'rev-parse' in args:
    target=args[-1].split(':',1)[1]
    print(pins[target])
elif 'cat-file' in args and args[-2]=='blob':
    value=blobs[args[-1]]
    if config['tamper'] and args[-1]==pins['deployment/apply_go_erp_bootstrap_memory_root.py']:
        value+='tampered\n'
    sys.stdout.write(value)
else:
    sys.exit(97)
''')
        self.executable('systemctl', r'''#!/usr/bin/python3
import json,os,sys
args=sys.argv[1:]
with open(os.environ['WRAPPER_EVENTS'],'a') as stream:
    stream.write(json.dumps(['systemctl',*args])+'\n')
config=json.load(open(os.environ['WRAPPER_CONFIG']))
if args==['is-active','--quiet','go-erp-live-updates.timer']:
    sys.exit(0 if config['active'] else 3)
if args==['stop','go-erp-live-updates.timer']:
    sys.exit(0)
if args==['start','go-erp-live-updates.timer']:
    sys.exit(1 if config['restore_fails'] else 0)
if args==['show','go-erp-live-updates.service','-p','ActiveState','--value']:
    config['checks']+=1
    with open(os.environ['WRAPPER_CONFIG'],'w') as stream:json.dump(config,stream)
    print('active' if config['busy'] or config['checks']<=config['busy_checks'] else 'inactive')
    sys.exit(0)
sys.exit(98)
''')

    def script(self, kind, status):
        return ("import json,sys\n"
            f"with open({str(self.operations)!r},'a') as stream:\n"
            f"    stream.write(json.dumps([{kind!r},*sys.argv[1:]])+'\\n')\n"
            f"print(json.dumps({{'kind':{kind!r},'ok':{status == 0!r}}}))\n"
            f"sys.exit({status})\n").encode()

    def executable(self, name, data):
        path = self.bin / name
        path.write_text(data)
        path.chmod(0o755)

    def run(self):
        environment = os.environ.copy()
        environment.update(PATH=str(self.bin) + ':/usr/bin:/bin',
            WRAPPER_EVENTS=str(self.events), WRAPPER_CONFIG=str(self.config),
            WRAPPER_PINS=str(self.pins), WRAPPER_BLOBS=str(self.blobs))
        return subprocess.run(['/bin/bash', str(self.wrapper), '--release', REVISION],
            env=environment, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=30)

    def calls(self):
        return [json.loads(line) for line in self.events.read_text().splitlines()]

    def steps(self):
        return ([json.loads(line) for line in self.operations.read_text().splitlines()]
                if self.operations.exists() else [])

    def close(self):
        self.temp.cleanup()


class BootstrapMemoryWrapperTests(unittest.TestCase):
    def fixture(self, **options):
        fixture = EntryFixture(**options)
        self.addCleanup(fixture.close)
        return fixture

    def assert_timer(self, fixture, stopped, started):
        calls = fixture.calls()
        self.assertEqual(calls.count(['systemctl', 'stop', 'go-erp-live-updates.timer']), stopped)
        self.assertEqual(calls.count(['systemctl', 'start', 'go-erp-live-updates.timer']), started)
        self.assertNotIn(['systemctl', 'stop', 'go-erp-live-updates.service'], calls)
        self.assertFalse(any(call[0] in ('kill', 'killall', 'pkill') for call in calls))

    def test_success_restores_only_originally_active_timer(self):
        fixture = self.fixture()
        result = fixture.run()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual([row[0] for row in fixture.steps()], ['patch', 'doctor'])
        self.assertIn('GO_ERP_BOOTSTRAP_MEMORY_PATCH_READY', result.stdout)
        self.assert_timer(fixture, stopped=1, started=1)
        # The staged root-private working directory is removed on success.
        staged_doctor = Path(fixture.steps()[0][1])
        self.assertFalse(staged_doctor.parent.exists())

    def test_patch_failure_restores_timer_and_does_not_run_postcheck(self):
        fixture = self.fixture(patch_status=7)
        result = fixture.run()
        self.assertEqual(result.returncode, 7)
        self.assertEqual([row[0] for row in fixture.steps()], ['patch'])
        self.assertNotIn('GO_ERP_BOOTSTRAP_MEMORY_PATCH_READY', result.stdout)
        self.assert_timer(fixture, stopped=1, started=1)

    def test_inactive_timer_is_preserved(self):
        fixture = self.fixture(active=False)
        result = fixture.run()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual([row[0] for row in fixture.steps()], ['patch', 'doctor'])
        self.assert_timer(fixture, stopped=0, started=0)

    def test_busy_existing_service_is_waited_for_but_never_killed(self):
        fixture = self.fixture(busy=True)
        result = fixture.run()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(fixture.steps(), [])
        self.assertEqual(fixture.calls().count(['sleep', '5']), 120)
        self.assertEqual(result.stdout.count('GO_ERP_MEMORY_PATCH_WAITING_SERVICE '),20)
        self.assertIn('waited_seconds=570 max_wait_seconds=600',result.stdout)
        self.assertIn('GO_ERP_MEMORY_PATCH_WAITING_FOR_EXISTING_SERVICE', result.stdout)
        self.assert_timer(fixture, stopped=1, started=1)

    def test_service_finishing_after_previous_limit_proceeds_without_killing(self):
        fixture = self.fixture(busy_checks=20)
        result = fixture.run()
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(fixture.calls().count(['sleep','5']),20)
        self.assertEqual([row[0] for row in fixture.steps()],['patch','doctor'])
        self.assert_timer(fixture,stopped=1,started=1)

    def test_termination_while_waiting_restores_timer_and_keeps_signal_status(self):
        fixture = self.fixture(busy=True,signal_on_sleep=True)
        result = fixture.run()
        self.assertEqual(result.returncode,143,result.stderr)
        self.assertEqual(fixture.steps(),[])
        self.assert_timer(fixture,stopped=1,started=1)

    def test_restore_failure_is_visible_and_fails_the_command(self):
        fixture = self.fixture(patch_status=7, restore_fails=True)
        result = fixture.run()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('GO_ERP_TIMER_RESTORE_FAILED', result.stderr)
        self.assert_timer(fixture, stopped=1, started=1)

    def test_bad_staged_pin_stops_before_any_timer_change(self):
        fixture = self.fixture(tamper=True)
        result = fixture.run()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(fixture.steps(), [])
        self.assertFalse(any(row[0] == 'systemctl' for row in fixture.calls()))

    def test_failed_postcheck_keeps_failure_and_restores_timer(self):
        fixture = self.fixture(doctor_status=6)
        result = fixture.run()
        self.assertEqual(result.returncode, 6)
        self.assertEqual([row[0] for row in fixture.steps()], ['patch', 'doctor'])
        self.assertNotIn('GO_ERP_BOOTSTRAP_MEMORY_PATCH_READY', result.stdout)
        self.assert_timer(fixture, stopped=1, started=1)



if __name__ == '__main__':
    unittest.main()
