"""Release wiring is authenticated separately from individual helper behavior."""
import ast
import base64
import hashlib
import importlib.util
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

BASE = Path(__file__).resolve().parents[1]
ORIGINAL = BASE/'deployment/run_go_erp_desktop_performance_root.py'
if not ORIGINAL.is_file():
    ORIGINAL = BASE.parent/'perf_operator/deployment/run_go_erp_desktop_performance_root.py'
TARGET = BASE/'deployment/run_go_erp_desktop_audit_successor_root.py'


def load(path, name):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec);spec.loader.exec_module(value)
    return value


class Bundle(unittest.TestCase):
    def test_original_seven_helpers_keep_the_exact_reviewed_bytes(self):
        old=load(ORIGINAL,'audit_original_bundle')
        new=load(TARGET,'audit_successor_bundle')
        self.assertEqual(len(new.ROLES),9)
        self.assertEqual(set(new.ROLES)-set(old.ROLES),{'successor_plan','successor_coordinator'})
        for role in old.ROLES:
            self.assertEqual(new.BUNDLED_MODULES[role],old.BUNDLED_MODULES[role])

    def test_new_bundled_helpers_match_the_reviewed_files_and_python39(self):
        new=load(TARGET,'audit_successor_bundled_files')
        ast.parse(TARGET.read_bytes(),feature_version=(3,9))
        for role,filename in {'successor_plan':'go_erp_desktop_performance_successor.py',
                              'successor_coordinator':'upgrade_go_erp_desktop_audit_successor_root.py'}.items():
            expected,encoded=new.BUNDLED_MODULES[role]
            raw=base64.b64decode(encoded,validate=True)
            self.assertEqual(raw,(BASE/'deployment'/filename).read_bytes())
            self.assertEqual(hashlib.sha256(raw).hexdigest(),expected)
            ast.parse(raw,feature_version=(3,9))

    def test_last_new_helper_failure_prevents_all_nine_from_executing(self):
        new=load(TARGET,'audit_successor_reject_bundle')
        bundle=dict(new.BUNDLED_MODULES)
        digest,raw=bundle['successor_coordinator']
        bundle['successor_coordinator']=('0'*64,raw)
        with patch.object(new,'BUNDLED_MODULES',bundle),patch.object(new,'load') as execute:
            with self.assertRaisesRegex(new.Stop,'MODULE_HASH_CHANGED'):
                new.helpers()
        execute.assert_not_called()

    def test_fetch_roster_contains_current_failed_and_patched_lineages(self):
        new=load(TARGET,'audit_successor_fetch_roster')
        roster={new.ROOT_REV,new.CORE,new.SEED,*new.DEPENDENCY_REVISIONS}
        self.assertTrue({
            '22f724b1ec75e8326e20305df40b91fe5640a7a7',
            '6a9afe74203995a36416373528de5a29097fee83',
            'b5503e21e3af32eecaf0d2f9d68eee7d21be7439',
            'd15942c947ac31c21a90b53856084a5de35d3cc3',
            '766f9d2d101fd639ffea8905e5b309f03eab8759',
            '6e39001095dbf5909c7b58cff69914c50da0f200',
            'f9c104bc0bfab1e8f3d0598b1800148f182c08bf',
        }<=roster)

    def test_builder_works_in_a_repository_without_scratch_siblings(self):
        builder=BASE/'build_successor_launcher.py'
        if not builder.is_file():
            builder=BASE/'deployment/build_successor_launcher.py'
        with tempfile.TemporaryDirectory(prefix='successor-repository-layout-') as temporary:
            root=Path(temporary)
            deployment=root/'deployment';deployment.mkdir()
            shutil.copyfile(ORIGINAL,deployment/ORIGINAL.name)
            for filename in ('go_erp_desktop_performance_successor.py',
                             'upgrade_go_erp_desktop_audit_successor_root.py'):
                shutil.copyfile(BASE/'deployment'/filename,deployment/filename)
            for folder in (root,deployment):
                target=folder/'build_successor_launcher.py'
                shutil.copyfile(builder,target)
                subprocess.run([sys.executable,str(target)],check=True,
                               stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
                built=load(deployment/TARGET.name,'audit_successor_portable_'+folder.name)
                self.assertEqual(set(built.ROLES),set(load(TARGET,'audit_successor_layout_expected').ROLES))
                for role in ('successor_plan','successor_coordinator'):
                    self.assertEqual(base64.b64decode(built.BUNDLED_MODULES[role][1]),
                                     (deployment/('go_erp_desktop_performance_successor.py'
                                        if role=='successor_plan'
                                        else 'upgrade_go_erp_desktop_audit_successor_root.py')).read_bytes())


if __name__=='__main__':
    unittest.main()
