#!/usr/bin/env python3
"""Root diagnostic entry stages verified inputs without activating any worker."""
import contextlib
import hashlib
import io
import json
import os
import pathlib
import signal
import sys
import types
import unittest
from unittest.mock import patch

ROOT=pathlib.Path(__file__).absolute().parents[1]
SOURCE=(ROOT/'deployment/diagnose_whatsapp_cairo_install_root.sh').read_text()
MARKER='exec /usr/bin/python3 - "$@" <<\'PY\'\n'
NS={'__name__':'root_diagnostic_fixture'}
exec(compile(SOURCE.split(MARKER,1)[1][:-len('\nPY\n')],'root-diagnostic','exec'),NS)


class RootDiagnosticTests(unittest.TestCase):
    def report(self):
        return {'diagnostic':'CAIRO_INSTALL_READ_ONLY','configuration_writes':0,'runtime_writes':0,
            'worker_actions':0,'network_requests':0,'ai_calls':0,'orders_created':0,'temporary_probe':True,
            'temporary_files_only':True,'checks_total':1,'checks_truncated':False,
            'checks':[{'stage':'SOURCE_READINESS','status':'FAILED','reason':'CONTROL_SHOW','origin_id':309}]}

    @unittest.skipUnless(os.geteuid()==0,'Root-owned staging fixture')
    def run_entry(self,fault=None):
        self.calls=[];self.staged=[];data=(ROOT/'deployment'/NS['DIAGNOSTIC']).read_bytes()
        def command(args,**kwargs):
            self.calls.append(args)
            if 'show' in args:return 0,(b'wrong helper' if fault=='pin' else data)
            self.assertIn('/usr/bin/python3',args);self.assertIn('-B',args)
            path=pathlib.Path(args[args.index('-B')+1]);self.assertEqual(path.stat().st_uid,0)
            self.assertEqual(path.stat().st_mode&0o777,0o644);self.assertEqual(path.read_bytes(),data)
            return (1,b'PRIVATE_CHILD_RESPONSE') if fault=='report' else (0,json.dumps(self.report()).encode())
        def stage(payload,release,runner):
            self.staged.append(payload);self.assertEqual(payload.stat().st_mode&0o777,0o755)
            (payload/'deployment').mkdir();(payload/'deployment').chmod(0o755)
        def prohibited(*args,**kwargs):raise AssertionError('activation or worker action invoked')
        wrapper={'legacy_program':lambda text:{'command':command},'stage_payload':stage,
                 'orchestrate':prohibited,'run_unit':prohibited}
        digests=[]
        def read_blob(digest):digests.append(digest);return b'fixture bootstrap'
        stdout=io.StringIO();stderr=io.StringIO();previous=signal.getsignal(signal.SIGTERM);old_mask=os.umask(0o077)
        try:
            with patch.object(sys,'argv',['diagnostic','--release','a'*40]), \
                 patch.object(NS['pwd'],'getpwnam',return_value=types.SimpleNamespace(pw_uid=0)), \
                 patch.dict(NS,{'read_blob':read_blob,'program':lambda data:wrapper}), \
                 contextlib.redirect_stdout(stdout),contextlib.redirect_stderr(stderr):code=NS['main']()
        finally:signal.signal(signal.SIGTERM,previous);os.umask(old_mask)
        self.assertEqual(digests,[NS['WRAPPER_BLOB'],NS['LEGACY_BLOB']])
        self.assertTrue(self.staged);self.assertTrue(all(not path.exists() for path in self.staged))
        self.assertFalse(any('/usr/bin/systemctl' in args or '/usr/bin/bash' in args or '--install' in args or '--enable' in args for args in self.calls))
        return code,stdout.getvalue(),stderr.getvalue()

    def test_root_entry_only_stages_runs_diagnostic_and_cleans_immutable_payload(self):
        code,output,error=self.run_entry();self.assertEqual(code,0);self.assertEqual(error,'')
        self.assertEqual(json.loads(output),self.report());self.assertEqual(len(self.calls),2)

    def test_bad_diagnostic_pin_refuses_before_application_and_cleans_payload(self):
        code,output,error=self.run_entry('pin');self.assertEqual(code,1);self.assertEqual(output,'')
        self.assertEqual(error,'CAIRO_DIAGNOSTIC_HELPER_PIN_FAILED\n');self.assertEqual(len(self.calls),1)

    def test_private_child_output_is_suppressed_and_payload_is_cleaned(self):
        code,output,error=self.run_entry('report');self.assertEqual(code,1);self.assertEqual(output,'')
        self.assertEqual(error,'CAIRO_DIAGNOSTIC_REPORT_INVALID\n');self.assertNotIn('PRIVATE_CHILD',error)

    def test_report_rejects_nonzero_writes_excessive_checks_or_unknown_private_fields(self):
        for key,value in [('runtime_writes',1),('checks',[{}]*43),('temporary_files_only',False)]:
            report=self.report();report[key]=value
            with self.subTest(key=key),self.assertRaises(NS['SafeError']):NS['validate_report'](0,json.dumps(report).encode())
        report=self.report();report['private_response']='secret'
        with self.assertRaises(NS['SafeError']):NS['validate_report'](0,json.dumps(report).encode())

    def test_bootstrap_blob_reader_verifies_hash_without_executing_blob(self):
        data=b'opaque bootstrap bytes';digest=NS['blob'](data)
        process=types.SimpleNamespace(communicate=lambda timeout:(data,None),returncode=0)
        with patch.object(NS['subprocess'],'Popen',return_value=process) as opened:
            self.assertEqual(NS['read_blob'](digest),data)
            with self.assertRaises(NS['SafeError']):NS['read_blob']('0'*40)
        self.assertTrue(all(call.args[0][:4]==[NS['RUNUSER'],'-u','fasakha','--'] for call in opened.call_args_list))
        self.assertEqual(hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest(),digest)


if __name__=='__main__':unittest.main()
