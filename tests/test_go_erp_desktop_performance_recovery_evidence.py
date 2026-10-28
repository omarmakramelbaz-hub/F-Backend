import copy
from contextlib import ExitStack
import hashlib
import inspect
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

BASE=Path(__file__).resolve().parents[1]


def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module


evidence=load('performance_recovery_evidence',BASE/'deployment/go_erp_desktop_performance_recovery_evidence.py')
fixture=BASE/'tests/fixtures/go_erp_desktop_performance/source_root.py'
if hashlib.sha256(fixture.read_bytes()).hexdigest()!='0e6cade4b9fd20a6a73ce7a79a587e67c69b9cecb1b6c852a62c531fcd2c5375':
    raise AssertionError('The pinned source transaction fixture changed')
original=load('performance_evidence_original',fixture)


class Engine:
    def __init__(self):self.reads=[];self.unit_bytes={}
    def read_file(self,path,maximum,uid=0,modes=(0o600,),optional=False):
        self.reads.append(Path(path));path=Path(path)
        if not path.exists():
            if optional:return None
            raise FileNotFoundError(path)
        info=path.lstat()
        if (not stat.S_ISREG(info.st_mode) or info.st_nlink!=1
                or info.st_uid!=uid or stat.S_IMODE(info.st_mode) not in modes):
            raise RuntimeError('Unsafe synthetic evidence file')
        raw=path.read_bytes()
        if not 0<len(raw)<=maximum:raise RuntimeError('Synthetic evidence size invalid')
        return raw,info
    def directory(self,path,uid,gid,mode,_create):
        info=Path(path).lstat()
        if not stat.S_ISDIR(info.st_mode) or (info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode))!=(uid,gid,mode):
            raise RuntimeError('Unsafe synthetic evidence directory')
    def units(self,_root):return self.unit_bytes


class Fixture(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory(prefix='performance-evidence-')
        self.addCleanup(self.temporary.cleanup)
        self.root=Path(self.temporary.name)
        self.e=Engine()
        self.r=SimpleNamespace(e=self.e,io=original)
        self.verifications=0
    def verify(self):self.verifications+=1;return True
    def write(self,path,value,mode=0o600):
        path.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
        if path.exists():path.chmod(0o600)
        path.write_bytes(evidence.canonical(value) if isinstance(value,dict) else value)
        path.chmod(mode)
        return path
    def patch(self,name,value):
        active=patch.object(evidence,name,value);active.start();self.addCleanup(active.stop)


class VerifierParsing(unittest.TestCase):
    def test_parses_without_executing_other_top_level_code(self):
        raw=("raise RuntimeError('must never execute')\nCORE = '"+evidence.CURRENT_CORE+"'\n").encode()
        self.assertEqual(evidence.verifier_core(raw),evidence.CURRENT_CORE)
    def test_rejects_computed_multiple_or_annotated_assignments(self):
        for raw in (b"CORE='a'*40\n",b"CORE='a'\nCORE='b'\n",b"CORE: str='a'\n",b'print(123)\n'):
            with self.subTest(raw=raw),self.assertRaises(evidence.Stop):evidence.verifier_core(raw)


class CommittedSource(Fixture):
    def prepare(self):
        root=self.root/'site';root.mkdir(mode=0o755)
        audits=self.root/'audits';audits.mkdir(mode=0o700)
        name='public/dashboard/js/order-board.js'
        self.write(root/name,b'before',0o644)
        after={name:b'after'}
        pins={name:{'before':original.sha(b'before'),'after':original.sha(b'after')}}
        def checked(io,payloads):return io.checked_plan(root,'a'*40,0,0,pins,payloads,())
        self.r.source=SimpleNamespace(AUDITS=audits,OWNER_UID=0,OWNER_GID=0,ROOT=root,PINS=pins,checked_plan=checked)
        self.r.public_payloads=after
        transaction=original.Transaction(root,audits,'a'*40,0,0,after,pins,(),self.verify)
        transaction.apply()
        self.patch('SOURCE_PLAN',transaction.digest)
        return transaction,root/name
    def test_rehydrates_only_committed_branch_without_any_writer_calls(self):
        transaction,path=self.prepare()
        with ExitStack() as stack:
            stack.enter_context(patch.object(original.Transaction,'__init__',side_effect=AssertionError('No constructor')))
            stack.enter_context(patch.object(original,'private_record',side_effect=AssertionError('No private writes')))
            stack.enter_context(patch.object(original,'private_directory',side_effect=AssertionError('No mkdir')))
            committed,digest=evidence._committed_source(self.r,self.verify)
        self.assertEqual(committed['attempt'],transaction.attempt.name)
        self.assertEqual(digest,transaction.digest)
        self.assertEqual(path.read_bytes(),b'after')
    def test_refuses_uncommitted_audit_without_creating_or_applying(self):
        transaction,path=self.prepare()
        (transaction.attempt/'committed.json').unlink()
        before=set(transaction.attempt.iterdir())
        with self.assertRaises(evidence.Stop):evidence._committed_source(self.r,self.verify)
        self.assertEqual(set(transaction.attempt.iterdir()),before)
        self.assertEqual(path.read_bytes(),b'after')
    def test_foreign_inode_is_rejected_by_original_committed_verifier(self):
        _transaction,path=self.prepare()
        other=path.with_name('foreign.js');self.write(other,b'after',0o644);other.replace(path)
        with self.assertRaises(original.Stop):evidence._committed_source(self.r,self.verify)


class ManualProof(Fixture):
    def prepare(self):
        payloads={name:('synthetic:'+name).encode() for name in evidence.BAD_MANUAL_PINS}
        pins={name:evidence.sha(raw) for name,raw in payloads.items()}
        runtime=evidence.sha(evidence.canonical(dict(sorted(pins.items()))))
        releases=self.root/'releases';releases.mkdir(mode=0o755)
        root=releases/runtime;root.mkdir(mode=0o700)
        self.addCleanup(lambda:root.chmod(0o700) if root.exists() else None)
        private=self.root/'private';private.mkdir(mode=0o700)
        systemd=self.root/'systemd';systemd.mkdir(mode=0o755)
        for name,raw in payloads.items():self.write(root/name,raw,0o444)
        root.chmod(0o555)
        self.e.unit_bytes={name:('unit:'+name).encode() for name in ('go-erp-manual-updates.path','go-erp-manual-updates.service')}
        for name,raw in self.e.unit_bytes.items():self.write(systemd/name,raw,0o644)
        receipt={'format':1,'kind':'go-erp-manual-installation','release':'a'*40,'runtime':runtime,
                 'units':{name:evidence.sha(raw) for name,raw in self.e.unit_bytes.items()}}
        self.write(private/'installation.json',receipt)
        self.r.package=SimpleNamespace(revision='a'*40)
        for name,value in {'BAD_MANUAL_PINS':pins,'MANUAL_ID':runtime,'MANUAL_RELEASES':releases,
                           'PRIVATE':private,'SYSTEMD':systemd}.items():self.patch(name,value)
        return payloads,receipt,root
    def test_exact_manual_bytes_receipt_units_are_required(self):
        payloads,receipt,_root=self.prepare()
        actual,hashes=evidence._manual(self.r,payloads)
        self.assertEqual(actual,receipt);self.assertEqual(hashes,receipt['units'])
        name=next(iter(payloads));payloads[name]+=b'foreign'
        with self.assertRaises(evidence.Stop):evidence._manual(self.r,payloads)
    def test_bad_release_or_installed_bytes_are_rejected(self):
        payloads,receipt,root=self.prepare()
        receipt['release']='b'*40;self.write(evidence.PRIVATE/'installation.json',receipt)
        with self.assertRaises(evidence.Stop):evidence._manual(self.r,payloads)
        receipt['release']='a'*40;self.write(evidence.PRIVATE/'installation.json',receipt)
        self.write(root/next(iter(payloads)),b'foreign',0o444)
        with self.assertRaises(evidence.Stop):evidence._manual(self.r,payloads)


class FailedAuditProof(Fixture):
    def prepare(self):
        work=self.root/'work';work.mkdir(mode=0o700);self.patch('WORK',work)
        self.path=work/('warm-start-seed-upgrade-'+'1'*32);self.path.mkdir(mode=0o700)
        self.state=b'{"counter":60}\n';self.config=evidence.canonical({'base_revision':evidence.CURRENT_SEED,'token':'private fixture'})
        self.unit=b'old unit\n'
        self.write(self.path/'before-config.bin',self.config)
        self.write(self.path/'exchange-config',{'base_revision':evidence.BAD_SEED,'token':'private fixture'})
        self.write(self.path/'before-state.bin',self.state);self.write(self.path/'before-unit.bin',self.unit)
        self.report={'format':1,'kind':'go-erp-preview31-watcher-upgrade','ok':False,'phase':'SEED_STAGE',
                     'reason':'SEED_CORE_MISMATCH','audit_directory':str(self.path)}
        self.write(self.path/('failed-'+'2'*32+'.json'),self.report)
        self.active=patch.object(evidence,'_checkout',return_value=(evidence.BAD_SEED,evidence.CURRENT_CORE))
        self.active.start();self.addCleanup(self.active.stop)
    def test_expected_failed_audit_returns_only_read_evidence(self):
        self.prepare()
        report,head,core=evidence._failed(self.r,self.state,self.config,self.unit,self.verify)
        self.assertEqual(report,self.report);self.assertEqual(head,evidence.BAD_SEED);self.assertEqual(core,evidence.CURRENT_CORE)
    def test_any_owned_intent_or_before_slot_change_is_rejected(self):
        self.prepare();self.write(self.path/'intent.json',{'forbidden':True})
        with self.assertRaises(evidence.Stop):evidence._failed(self.r,self.state,self.config,self.unit,self.verify)
        (self.path/'intent.json').unlink();self.write(self.path/'before-state.bin',b'foreign')
        with self.assertRaises(evidence.Stop):evidence._failed(self.r,self.state,self.config,self.unit,self.verify)


class CollectorResume(unittest.TestCase):
    def test_saved_before_records_remain_plan_inputs_after_partial_cas(self):
        observation={'candidate':'c'*40}
        old_performance={'current':observation,'binding_sha256':'old binding','phase':'prepared'}
        old_job={'audit':str(evidence.PRIVATE/'audits'/('warm-start-'+'a'*32)),'seed':evidence.BAD_SEED}
        rollout=SimpleNamespace(
            flow={**old_performance,'binding_sha256':'new binding'},
            bad_binding={'seed':'bad'},bind={'seed':'replacement'},
            current=SimpleNamespace(record=lambda value:copy.deepcopy(value)),
            io=SimpleNamespace(parse=lambda raw:json.loads(raw)))
        checkpoint=evidence.AUDIT/('checkpoint-'+evidence.sha(evidence.canonical(old_performance))+'.json')
        records={checkpoint:evidence.canonical(old_performance),
                 evidence.WORK/'state/current.json':b'original state',
                 evidence.CONFIG:b'original config',
                 evidence.SYSTEMD/'go-erp-live-updates.service':b'original unit',
                 Path(old_job['audit'])/'previous-state.json':b'original state',
                 Path(old_job['audit'])/'captured-source.json':b'captured metadata',
                 evidence.WORK/'program-rollout-hold.json':None}
        calls=[]
        def read(_rollout,path,*_args,**_kwargs):
            calls.append(path)
            return records[path]
        saved={'performance_journal':old_performance,'native_job':old_job,
               'completed':False,'raws':{'native-before.bin':b'private WAL'}}
        with ExitStack() as stack:
            stack.enter_context(patch.object(evidence,'_read',side_effect=read))
            stack.enter_context(patch.object(evidence,'_committed_source',return_value=({'attempt':'attempt-000000'},'source digest')))
            stack.enter_context(patch.object(evidence,'_manual',return_value=({'runtime':'bad manual'}, {'service':'unit hash'})))
            stack.enter_context(patch.object(evidence,'_failed',return_value=({'reason':'SEED_CORE_MISMATCH'},evidence.BAD_SEED,evidence.CURRENT_CORE)))
            checkout=stack.enter_context(patch.object(evidence,'_checkout'))
            kwargs=evidence.collect(rollout,observation,{'public code':b'bad manual'},lambda:True,saved=saved)
        self.assertEqual(kwargs['performance_journal'],old_performance)
        self.assertEqual(kwargs['native_job'],old_job)
        self.assertEqual(kwargs['performance_checkpoint_raw'],evidence.canonical(old_performance))
        self.assertEqual(kwargs['native_state_raw'],kwargs['previous_state_raw'])
        self.assertNotIn(evidence.PRIVATE/('shared-workspace-'+evidence.CORE+'.json'),calls)
        checkout.assert_called_once_with(rollout,evidence.WORK/'checkout',evidence.CURRENT_SEED)
        planner=load('performance_evidence_plan_signature',BASE/'deployment/go_erp_desktop_performance_recovery.py')
        self.assertEqual(set(kwargs),set(inspect.signature(planner.plan).parameters))


if __name__=='__main__':unittest.main()
