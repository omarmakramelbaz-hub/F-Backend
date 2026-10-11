#!/usr/bin/env python3
"""Activation orchestration: ordering, availability recovery and process quiescence."""
import contextlib
import hashlib
import io
import json
import os
import pathlib
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from unittest import mock

ROOT=pathlib.Path(__file__).absolute().parents[1]
TEXT=(ROOT/'deployment/enable_whatsapp_automatic_root.sh').read_text()
MARKER='exec /usr/bin/python3 - "$@" <<\'PY\'\n'
assert TEXT.count(MARKER)==1 and TEXT.endswith('\nPY\n')
PROGRAM=TEXT.split(MARKER,1)[1][:-len('\nPY\n')]
NS={'__name__':'automatic_enable_fixture'};exec(compile(PROGRAM,'enable-wrapper','exec'),NS)


@unittest.skipUnless(os.geteuid()==0,'Root-owned fixture payload exercises privileged staging guards')
class OrchestrationTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.parent=pathlib.Path(self.tmp.name);self.root=self.parent/'public_html';self.root.mkdir()
        self.payload=self.parent/'helpers';self.payload.mkdir(mode=0o755)
        for name in NS['HELPER_PINS']:
            path=self.payload/name;path.write_bytes((ROOT/'deployment'/name).read_bytes());path.chmod(0o644)
        self.pins={}
        for index,relative in enumerate((
            'app/Services/Dashboard/WhatsAppInboxConsumer.php','app/Console/Commands/ConsumeWhatsAppInbox.php',
            'app/Services/Dashboard/ProtectedUi.php',NS['CART_PATH'])):
            path=self.root/relative;path.parent.mkdir(parents=True,exist_ok=True)
            data=('reviewed-source-'+str(index)).encode();path.write_bytes(data);path.chmod(0o644)
            self.pins[relative]=NS['blob'](data)
        capture={path:self.pins[path]for path in tuple(NS['CAPTURE_PINS'])}
        patcher=mock.patch.dict(NS['CAPTURE_PINS'],capture,clear=True);patcher.start();self.addCleanup(patcher.stop)
        self.states={
            NS['CAPTURE_TIMER']:{'LoadState':'loaded','ActiveState':'active','UnitFileState':'enabled'},
            NS['CAPTURE_SERVICE']:{'LoadState':'loaded','ActiveState':'inactive','UnitFileState':'static'},
            NS['ORDER_TIMER']:{'LoadState':'loaded','ActiveState':'inactive','UnitFileState':'disabled'},
            NS['ORDER_SERVICE']:{'LoadState':'loaded','ActiveState':'inactive','UnitFileState':'static'},
        }
        self.log=[];self.fail=None;self.backup='/home/fasakha/whatsapp-release-backups/automatic-activation-fixture'

    def validate(self,kind):
        self.log.append(('validate',kind))
        if self.fail=='unit_drift'and kind=='capture':raise NS['SafeError']('AUTOMATIC_UNIT_BYTES_CHANGED: capture')

    def runner(self,args,timeout=60):
        self.log.append(tuple(args))
        if args[0]==NS['SYSTEMCTL']:
            op=args[1]
            if op=='show':
                return 0,('\n'.join(k+'='+v for k,v in self.states[args[2]].items())+'\n').encode()
            for name in args[2:]:
                if name=='--runtime':continue
                if op=='stop':self.states[name]['ActiveState']='inactive'
                if op=='start':self.states[name]['ActiveState']='active'
                if op=='enable':self.states[name]['UnitFileState']='enabled'
                if op=='disable':self.states[name]['UnitFileState']='disabled'
            return 0,b''
        if args[0]=='/usr/bin/bash':
            kind='capture'if'capture'in pathlib.Path(args[1]).name else'orders';flag=args[2]
            if self.fail=='signal'and kind=='orders'and flag=='--stop':
                self.fail=None;raise NS['SafeError']('AUTOMATIC_ENABLE_INTERRUPTED')
            if kind=='orders'and flag=='--stop':
                for name in (NS['ORDER_TIMER'],NS['ORDER_SERVICE']):self.states[name]['ActiveState']='inactive'
            if kind=='orders'and flag=='--start':
                self.states[NS['ORDER_TIMER']]['UnitFileState']='enabled'
                if self.fail=='start':return 1,b'PRIVATE_CHILD_ERROR_MUST_NOT_LEAK'
                self.states[NS['ORDER_TIMER']]['ActiveState']='active'
            return 0,b'WHATSAPP_UNIT_ACTION_OK\n'
        if args[0]==NS['RUNUSER']and args[4]==NS['PHP']:
            if self.fail=='ai':return 1,b'{"status":"AI_CONNECTION_FAILED","ai_calls":1,"reason":"HTTP_ERROR"}'
            self.assertEqual(timeout,240)
            return 0,b'{"status":"AI_CONNECTION_READY","ai_calls":1}'
        if args[0]==NS['RUNUSER']and args[4]=='/usr/bin/env':
            self.assertEqual(timeout,300)
            if self.fail=='activation':return 1,('ACTIVATION_CONFIGURATION_RESTORED; BACKUP='+self.backup+'\nPRIVATE_CHILD_ERROR_MUST_NOT_LEAK').encode()
            (self.root/NS['CART_PATH']).write_bytes(b'authorized-activation-config')
            return 0,('WHATSAPP_AUTOMATIC_CONFIGURED_TIMER_START_REQUIRED\nBACKUP='+self.backup+'\nACTIVATION={}\n').encode()
        raise AssertionError('Unexpected command')

    def run_wrapper(self):
        self.output=io.StringIO()
        with contextlib.redirect_stdout(self.output):
            return NS['orchestrate'](self.payload,0,self.runner,self.validate,self.root,self.pins)

    def assert_capture_available(self):
        self.assertEqual(self.states[NS['CAPTURE_TIMER']]['ActiveState'],'active')
        self.assertEqual(self.states[NS['CAPTURE_TIMER']]['UnitFileState'],'enabled')
        self.assertEqual(self.states[NS['ORDER_TIMER']]['ActiveState'],'inactive')
        self.assertEqual(self.states[NS['ORDER_TIMER']]['UnitFileState'],'disabled')

    def test_success_orders_ai_before_pause_and_disables_capture_only_after_order_active(self):
        self.assertEqual(self.run_wrapper(),('WHATSAPP_AUTOMATIC_ENABLED',self.backup))
        commands=[x for x in self.log if x[0]!='validate']
        find=lambda predicate:next(i for i,args in enumerate(commands)if predicate(args))
        ai=find(lambda a:a[0]==NS['RUNUSER']and a[4]==NS['PHP'])
        pause=find(lambda a:a[:2]==(NS['SYSTEMCTL'],'stop'))
        activation=find(lambda a:a[0]==NS['RUNUSER']and a[4]=='/usr/bin/env')
        start=find(lambda a:a[0]=='/usr/bin/bash'and a[2]=='--start')
        disable=find(lambda a:a==(NS['SYSTEMCTL'],'disable',NS['CAPTURE_TIMER']))
        self.assertLess(ai,pause);self.assertLess(pause,activation);self.assertLess(activation,start);self.assertLess(start,disable)
        self.assertFalse(any(a[0]=='/usr/bin/bash'and'capture'in a[1]for a in commands))
        self.assertEqual(self.states[NS['ORDER_TIMER']]['ActiveState'],'active')
        self.assertEqual(self.states[NS['CAPTURE_TIMER']]['UnitFileState'],'disabled')

    def test_ai_failure_leaves_capture_active_and_no_activation(self):
        self.fail='ai'
        with self.assertRaisesRegex(NS['SafeError'],'AI_CHECK_FAILED_CAPTURE_REMAINS_RUNNING'):self.run_wrapper()
        self.assert_capture_available()
        self.assertFalse(any(a[:2]==(NS['SYSTEMCTL'],'stop')for a in self.log))

    def test_activation_failure_restores_capture_and_preserves_backup_reference(self):
        self.fail='activation'
        with self.assertRaisesRegex(NS['SafeError'],'CAPTURE_AVAILABILITY_RESTORED; BACKUP='+self.backup):self.run_wrapper()
        self.assert_capture_available();self.assertNotIn('PRIVATE_CHILD_ERROR',self.output.getvalue())

    def test_order_start_failure_restores_previous_enabled_state_and_capture(self):
        self.fail='start'
        with self.assertRaisesRegex(NS['SafeError'],'CAPTURE_AVAILABILITY_RESTORED; BACKUP='+self.backup):self.run_wrapper()
        self.assert_capture_available();self.assertNotIn('PRIVATE_CHILD_ERROR',self.output.getvalue())

    def test_signal_after_pause_restores_capture(self):
        self.fail='signal'
        with self.assertRaisesRegex(NS['SafeError'],'AUTOMATIC_ENABLE_INTERRUPTED; CAPTURE_AVAILABILITY_RESTORED'):self.run_wrapper()
        self.assert_capture_available()

    def test_unit_or_helper_drift_refuses_before_any_paid_call_or_pause(self):
        self.fail='unit_drift'
        with self.assertRaisesRegex(NS['SafeError'],'UNIT_BYTES_CHANGED'):self.run_wrapper()
        self.assertFalse(any(x[0]in(NS['RUNUSER'],NS['SYSTEMCTL'],'/usr/bin/bash')for x in self.log))
        self.fail=None;self.log=[]
        (self.payload/'activate_whatsapp_automatic.py').write_bytes(b'changed helper')
        with self.assertRaisesRegex(NS['SafeError'],'GUARDED_BLOB_OR_RACE_FAILED'):self.run_wrapper()
        self.assertEqual(self.log,[])

    def test_guarded_payload_staging_rejects_wrong_blob_before_commands(self):
        directory=self.parent/'newstage';directory.mkdir(mode=0o755)
        calls=[]
        def git(args):
            calls.append(args)
            return (0,b'commit\n')if'cat-file'in args else(0,b'changed helper')
        with self.assertRaisesRegex(NS['SafeError'],'HELPER_PIN_FAILED'):
            NS['stage_helpers'](directory,'a'*40,git)
        self.assertEqual(list(directory.iterdir()),[])


class ProcessGroupTests(unittest.TestCase):
    def child_program(self,folder):
        # The grandchild ignores TERM and closes the leader's output pipes.
        path=pathlib.Path(folder)/'child.pid'
        leader=("import os,pathlib,signal,time;child=os.fork()\n"
                "if child==0:\n"
                " signal.signal(signal.SIGTERM,signal.SIG_IGN)\n"
                " fd=os.open(os.devnull,os.O_WRONLY);os.dup2(fd,1);os.dup2(fd,2);os.close(fd)\n"
                " pathlib.Path("+repr(str(path))+").write_text(str(os.getpid()))\n"
                " while True:\n"
                "  with open("+repr(str(path)+'.heartbeat')+",'ab')as tick:tick.write(b'x');tick.flush()\n"
                "  time.sleep(0.01)\n"
                "else:time.sleep(60)\n")
        return path,['/usr/bin/python3','-c',leader]

    def assert_child_not_running(self,path):
        self.assertTrue(path.exists())
        heartbeat=pathlib.Path(str(path)+'.heartbeat')
        self.assertTrue(heartbeat.exists())
        before=heartbeat.read_bytes();self.assertGreater(len(before),0)
        time.sleep(0.2)
        self.assertEqual(heartbeat.read_bytes(),before,'A descendant kept mutating after group cleanup')

    def test_timeout_kills_pipe_detached_term_ignoring_descendant(self):
        with tempfile.TemporaryDirectory()as folder:
            path,args=self.child_program(folder)
            code,_=NS['command'](args,root=pathlib.Path(folder),timeout=0.3)
            self.assertNotEqual(code,0);self.assert_child_not_running(path)

    def test_interrupt_kills_pipe_detached_term_ignoring_descendant(self):
        with tempfile.TemporaryDirectory()as folder:
            path,args=self.child_program(folder)
            previous=signal.signal(signal.SIGALRM,lambda *_:(_ for _ in ()).throw(NS['SafeError']('INTERRUPT_FIXTURE')))
            try:
                signal.setitimer(signal.ITIMER_REAL,0.3)
                with self.assertRaisesRegex(NS['SafeError'],'INTERRUPT_FIXTURE'):
                    NS['command'](args,root=pathlib.Path(folder),timeout=10)
            finally:
                signal.setitimer(signal.ITIMER_REAL,0);signal.signal(signal.SIGALRM,previous)
            self.assert_child_not_running(path)


if __name__=='__main__':unittest.main()
