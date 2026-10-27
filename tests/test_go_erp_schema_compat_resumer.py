import ast
import copy
from contextlib import contextmanager
import hashlib
import importlib.util
from pathlib import Path
import types
import unittest

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('compat_resumer',ROOT/'deployment/resume_go_erp_shared_workspace_schema_compat_root.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
raw=(ROOT/'deployment/go_erp_shared_workspace_root.py').read_bytes()
pins=next(ast.literal_eval(n.value) for n in ast.parse(raw).body if isinstance(n,ast.Assign)
    and len(n.targets)==1 and isinstance(n.targets[0],ast.Name) and n.targets[0].id=='PINS')
original=(ROOT/'deployment/go_erp_shared_workspace_migration.php').read_bytes()
compat=(ROOT/m.COMPAT_TEMPLATE).read_bytes()
workflow=b'exact reviewed focused workflow'

def approval():
    rows=[]
    for f in ('8.83.29','12.70.0'):
        for e in ('mysql','mariadb'):
            for t in (0,1):
                rows.append({'framework':f,'engine':e,'initial_timestamp':t,'actual_pdo':True,
                    'full_helper_body':True,'created_tables':4,'recorded_migrations':2,'all_schemas_match':True,
                    'source_migrations_unchanged':True,'compat_template_sha256':m.COMPAT_SHA256,
                    'receipt_sha256':'a'*64,'artifact_sha256':'b'*64,'original_swap_failure_reproduced':f=='8.83.29'})
    return {'kind':'go-erp-reviewed-full-helper-schema-compatibility','accepted':True,'core':m.CORE,
        'base_operator':m.BASE_OPERATOR,'base_pins_sha256':m.BASE_PINS_SHA256,'compat_template_sha256':m.COMPAT_SHA256,
        'workflow':{'path':m.WORKFLOW,'event':'push','status':'completed','conclusion':'success',
            'head_sha':'c'*40,'id':123,'attempt':1},'workflow_sha256':m.sha(workflow),'profiles':rows}

class Package:
    def __init__(self):self.template=compat;self.workflow=workflow
    def file(self,name,revision=None):
        if name==m.COMPAT_TEMPLATE:return self.template
        if name==m.WORKFLOW:return self.workflow
        raise AssertionError('unexpected package access')

class BaseOperator:
    def __init__(self,package,p):
        self.package=package;self.pins=copy.deepcopy(p);self.original_checks=0;self.audit=Path('/unused')
        self.template_at_validation=self._migration_template()
    def _migration_template(self):
        self.original_checks+=1
        if m.sha(original)!=self.pins['migration_template_sha256']:raise ValueError('original pin failure')
        return original
    @contextmanager
    def held(self):yield lambda:True
    def _load(self):return self.flow_state
    def run(self):return {'original_engine_used':True}

base=types.SimpleNamespace(PINS=pins,Operator=BaseOperator)

class CompatibilityTests(unittest.TestCase):
    def test_unarmed_resumer_cannot_run(self):
        self.assertIsNone(m.APPROVAL)
        with self.assertRaises(m.Stop):m.approved(m.APPROVAL)
    def test_full_profiles_required(self):
        value=approval();value['profiles'].pop()
        with self.assertRaises(m.Stop):m.approved(value)
    def test_old_failure_must_be_reproduced_on_laravel8(self):
        value=approval();value['profiles'][0]['original_swap_failure_reproduced']=False
        with self.assertRaises(m.Stop):m.approved(value)
    def test_boolean_is_not_timestamp_integer(self):
        value=approval();value['profiles'][0]['initial_timestamp']=False
        with self.assertRaises(m.Stop):m.approved(value)
    def test_exact_base_core_pin_cannot_be_rebound(self):
        value=approval();value['base_pins_sha256']='d'*64
        with self.assertRaises(m.Stop):m.approved(value)
    def test_accepted_workflow_bytes_must_match(self):
        package=Package();package.workflow=b'changed after acceptance'
        with self.assertRaises(m.Stop):m.build_operator(base,package,approval())
    def test_accepted_php_bytes_must_match(self):
        package=Package();package.template=compat+b'\n'
        with self.assertRaises(m.Stop):m.build_operator(base,package,approval())
    def test_original_template_pin_is_checked_before_new_template(self):
        op=m.build_operator(base,Package(),approval());self.assertEqual(op.original_checks,1)
        self.assertEqual(op.template_at_validation,compat);self.assertEqual(op.pins,pins)
        self.assertEqual(m.sha(m.canonical(op.pins)),m.BASE_PINS_SHA256)
    def test_finished_unaccepted_flow_cannot_adopt_new_compatibility(self):
        op=m.build_operator(base,Package(),approval());op.flow_state={'phase':'prepared','prepared':{},'activation':None}
        op.io=types.SimpleNamespace(read=lambda *a,**k:None)
        with self.assertRaises(m.Stop):op._accept_compatibility()
    def test_existing_exact_receipt_permits_original_later_phase_resume(self):
        value=approval();op=m.build_operator(base,Package(),value);op.flow_state={'phase':'ready'}
        receipt={'format':1,'kind':'go-erp-schema-adapter-compatibility-acceptance',
            'base_operator':m.BASE_OPERATOR,'base_pins_sha256':m.BASE_PINS_SHA256,'core':m.CORE,
            'compat_template_sha256':m.COMPAT_SHA256,'full_helper_acceptance_sha256':m.sha(m.canonical(value)),
            'migration_hashes':pins['migration_hashes'],'migration_schemas':pins['migration_schemas']}
        op.io=types.SimpleNamespace(read=lambda *a,**k:(m.canonical(receipt),None),canonical=m.canonical)
        self.assertEqual(op.run(),{'original_engine_used':True})
    def test_compatibility_receipt_from_another_acceptance_rejected(self):
        op=m.build_operator(base,Package(),approval());op.flow_state={'phase':'ready'}
        op.io=types.SimpleNamespace(read=lambda *a,**k:(b'{}\n',None),canonical=m.canonical)
        with self.assertRaises(m.Stop):op.run()

if __name__=='__main__':unittest.main()
