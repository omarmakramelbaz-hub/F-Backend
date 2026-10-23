"""The bounded navigation entry never prints account/config/error text."""
import importlib.util
import io
import json
from pathlib import Path
import shutil
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
# Production exports the entry as root-owned 0644. A sudo CI run does not
# change checkout ownership, so copy it into the actual production trust shape.
ENTRY_DIRECTORY=tempfile.TemporaryDirectory(prefix='confirmation-root-entry-')
ENTRY=Path(ENTRY_DIRECTORY.name)/'upgrade_go_erp_confirmation_nav_successor_root.py'
shutil.copyfile(ROOT/'deployment/upgrade_go_erp_confirmation_nav_successor_root.py',ENTRY);ENTRY.chmod(0o644)
SPEC=importlib.util.spec_from_file_location('confirmation_safe_failure',ENTRY)
M=importlib.util.module_from_spec(SPEC);sys.modules[SPEC.name]=M;SPEC.loader.exec_module(M)

class FailureTests(unittest.TestCase):
    def capture(self,action,package):
        output=io.StringIO()
        with patch.object(M,'execute',lambda *args:action()),patch('sys.stdout',output):status=M.main(['--package',str(package),'--prepare'])
        text=output.getvalue();self.assertEqual(len(text.splitlines()),1)
        for secret in ('PRIVATE_ACCOUNT_TOKEN','/private/account','foreign.py','password=secret'):self.assertNotIn(secret,text)
        return status,json.loads(text)
    def loaded(self,package,name,source):
        path=package/name;path.write_bytes(source);path.chmod(0o644)
        value=types.ModuleType('verified_confirmation_'+path.stem);value.__file__=str(path);sys.modules[value.__name__]=value
        exec(compile(source,str(path),'exec'),value.__dict__);return value
    def test_real_pinned_helper_reason_and_innermost_location(self):
        with tempfile.TemporaryDirectory() as tmp:
            package=Path(tmp);source=(ROOT/'deployment/go_erp_confirmation_nav_application_patch.py').read_bytes()
            helper=self.loaded(package,'go_erp_confirmation_nav_application_patch.py',source)
            status,row=self.capture(lambda:helper.need(False,'LOCK_UNSAFE'),package)
            self.assertEqual(status,1);self.assertEqual(row['reason'],'GO_ERP_PROGRAM_APPLICATION_LOCK_UNSAFE')
            self.assertEqual(row['diagnostic']['file'],'go_erp_confirmation_nav_application_patch.py')
            self.assertEqual(row['diagnostic']['site'],'raise');self.assertEqual(row['diagnostic']['type'],'Stop')
            self.assertGreater(row['diagnostic']['line'],0);self.assertNotIn(tmp,json.dumps(row))
    def test_private_builtin_and_dynamic_pinned_need_are_not_public_reasons(self):
        with tempfile.TemporaryDirectory() as tmp:
            package=Path(tmp);helper=self.loaded(package,'go_erp_confirmation_nav_application_patch.py',(ROOT/'deployment/go_erp_confirmation_nav_application_patch.py').read_bytes())
            for action in (lambda:helper.need(False,'PRIVATE_ACCOUNT_TOKEN'),lambda:helper.need(False,'/private/account/password=secret')):
                _,row=self.capture(action,package);self.assertEqual(row['reason'],'GO_ERP_NAV_SUCCESSOR_PRIVATE_FAILURE')
                self.assertEqual(row['diagnostic']['file'],'go_erp_confirmation_nav_application_patch.py')
            def builtin():raise OSError('PRIVATE_ACCOUNT_TOKEN /private/account/password=secret')
            _,row=self.capture(builtin,package);self.assertEqual(row['reason'],'GO_ERP_NAV_SUCCESSOR_PRIVATE_FAILURE')
            self.assertEqual(row['diagnostic']['type'],'OSError');self.assertEqual(row['diagnostic']['site'],'caller')
    def test_foreign_genuine_stop_need_subclass_and_spoofed_filename_stay_private(self):
        with tempfile.TemporaryDirectory() as tmp:
            for expression in ('raise genuine("PRIVATE_ACCOUNT_TOKEN")','need(False,"PRIVATE_ACCOUNT_TOKEN")','raise custom("PRIVATE_ACCOUNT_TOKEN")'):
                class PrivateStop(M.Stop):pass
                namespace={'genuine':M.Stop,'need':M.need,'custom':PrivateStop}
                exec(compile('def foreign():\n    '+expression+'\n',str(Path(tmp)/'go_erp_confirmation_nav_application_patch.py'),'exec'),namespace)
                _,row=self.capture(namespace['foreign'],tmp);self.assertEqual(row['reason'],'GO_ERP_NAV_SUCCESSOR_PRIVATE_FAILURE')
                self.assertEqual(row['diagnostic']['file'],'upgrade_go_erp_confirmation_nav_successor_root.py')
    def test_changed_helper_bytes_and_foreign_defining_globals_cannot_claim_trust(self):
        with tempfile.TemporaryDirectory() as tmp:
            package=Path(tmp);name='go_erp_confirmation_nav_application_patch.py';source=(ROOT/'deployment'/name).read_bytes()
            helper=self.loaded(package,name,source+b'\n# changed unreviewed bytes\n')
            _,row=self.capture(lambda:helper.need(False,'LOCK_UNSAFE'),package)
            self.assertEqual(row['reason'],'GO_ERP_NAV_SUCCESSOR_PRIVATE_FAILURE');self.assertEqual(row['diagnostic']['site'],'caller')
            path=package/name;path.write_bytes(source);namespace={'__file__':str(path),'__name__':'foreign_unregistered_confirmation'}
            exec(compile(source,str(path),'exec'),namespace)
            _,row=self.capture(lambda:namespace['need'](False,'LOCK_UNSAFE'),package)
            self.assertEqual(row['reason'],'GO_ERP_NAV_SUCCESSOR_PRIVATE_FAILURE');self.assertEqual(row['diagnostic']['site'],'caller')
    def test_own_fixed_enum_remains_observable_without_stringifying_unknown_errors(self):
        with tempfile.TemporaryDirectory() as tmp:
            _,row=self.capture(lambda:M.need(False,'SOURCE_UNREVIEWED'),tmp)
            self.assertEqual(row['reason'],'GO_ERP_NAV_SUCCESSOR_SOURCE_UNREVIEWED');self.assertEqual(row['diagnostic']['site'],'raise')
            class Unprintable(Exception):
                def __str__(self):raise AssertionError('must not stringify error')
            def fail():raise Unprintable('PRIVATE_ACCOUNT_TOKEN')
            _,row=self.capture(fail,tmp);self.assertEqual(row['reason'],'GO_ERP_NAV_SUCCESSOR_PRIVATE_FAILURE');self.assertEqual(row['diagnostic']['type'],'Exception')

if __name__=='__main__':unittest.main()
