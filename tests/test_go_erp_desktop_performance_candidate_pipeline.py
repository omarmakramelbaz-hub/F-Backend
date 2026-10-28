"""Real Git: unchanged allocation -> source candidate -> policy delta guard.

These are isolated public-code fixtures, not production release receipts. The
publisher's remote release-schema validator is represented by a local version
fixture. Allocation, durable save, source generation and Git policy validation
execute their pinned original implementations without method replacements.
"""
import ast
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest

BASE=Path(__file__).resolve().parents[1]
FIXTURES=BASE/'tests/fixtures/go_erp_desktop_performance'


def load(name,path,expected=None):
    raw=path.read_bytes()
    if expected is not None and hashlib.sha256(raw).hexdigest()!=expected:
        raise AssertionError('Pinned candidate pipeline fixture changed: '+path.name)
    spec=importlib.util.spec_from_file_location(name,path)
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value)
    return value


worker=load('pipeline_original_worker',FIXTURES/'worker_publisher.py',
    'f023f00dc33023efdb65de7133284928e3491028408d5bf7e8247dbf75288650')
source=load('pipeline_original_source',FIXTURES/'source_candidate.py',
    '0da17f1562b6e1a4465cdfb56270b3f8f7eca41b7c4c8e737aa0e7d3a3b66ccd')
policy=load('pipeline_original_policy',FIXTURES/'seed_policy.py',
    '2de7ec3884760a2b566f45469aa7aa1d2b31d6a605be16fe496af9b998ce15bc')
rollout=load('pipeline_current_rollout',BASE/'deployment/upgrade_go_erp_desktop_performance_root.py')


class Pipeline(unittest.TestCase):
    def write(self,path,value):
        path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
        path.write_bytes(value if isinstance(value,bytes) else json.dumps(value).encode()+b'\n')
        path.chmod(0o644)

    def commit(self,checkout,message):
        source._git(checkout,['add','.'])
        source._git(checkout,['commit','--quiet','-m',message])
        return source._git(checkout,['rev-parse','HEAD']).decode().strip()

    def fixture(self,version=60):
        temporary=tempfile.TemporaryDirectory(prefix='go-erp-candidate-pipeline-')
        self.addCleanup(temporary.cleanup)
        root=Path(temporary.name);checkout=root/'checkout';checkout.mkdir(mode=0o700)
        source._git(checkout,['init','--quiet','--template='])
        source._git(checkout,['remote','add','origin',source.REMOTE_URL])
        files={name:b'<?php // isolated source fixture\n' for name in source.CRITICAL}
        files.update({'desktop/source-contract.json':b'{"framework":"8.83.29"}\n',
            'app/captured.php':b'<?php // old captured feature\n',
            'public/site/images/Cart \u2013 1.png':b'public fixture image bytes\n'})
        for name,raw in files.items():self.write(checkout/name,raw)
        package_version='0.4.4-preview.'+str(version)
        self.write(checkout/'desktop-pos/package.json',{'version':package_version})
        self.write(checkout/'desktop-pos/package-lock.json',
                   {'version':package_version,'packages':{'':{'version':package_version}}})
        core=self.commit(checkout,'reviewed native core and published version baseline')
        for name in policy.POLICY_FILES:
            if name=='deployment/verify_go_erp_preview31_seed.py':
                raw=(FIXTURES/'seed_policy.py').read_bytes()
                anchor=("CORE = '"+policy.CORE+"'").encode()
                self.assertEqual(raw.count(anchor),1)
                raw=raw.replace(anchor,("CORE = '"+core+"'").encode())
            else:raw=('# permitted isolated policy '+name+'\n').encode()
            self.write(checkout/name,raw)
        seed=self.commit(checkout,'exact three policy files in a single child')
        self.assertEqual(policy.validate_seed(checkout,seed,core=core)['seed'],seed)
        revision,seed_files=source._seed(checkout)
        self.assertEqual(revision,seed)
        captured={name:raw for name,(raw,_mode) in seed_files.items()}
        captured['app/captured.php']=b'<?php // new captured feature\n'
        inventory={name:{'authority':'live','present':True,'sha256':source._digest(raw),
            'bytes':len(raw),'mode':seed_files[name][1]} for name,raw in captured.items()}
        snapshot={'metadata':source._metadata(seed,inventory),'_files':captured}
        live=root/'live';live.mkdir(mode=0o700)
        feed=live/'storage/app/desktop-updates/current.json'
        self.write(feed,{'version':'0.4.4-preview.60'})
        state_folder=root/'state';state_folder.mkdir(mode=0o700)
        instance=object.__new__(worker.Worker)
        instance.checkout,instance.live=checkout,live
        instance.config={'base_revision':seed}
        instance.state={'format':1,'base_revision':seed,'counter':60,'version_floor':'0.4.4-preview.60'}
        instance.state_file=state_folder/'current.json'
        instance.publisher=SimpleNamespace(read_json=lambda path,**_kwargs:json.loads(path.read_bytes()),
                                           validate_release=lambda value:value)
        return SimpleNamespace(checkout=checkout,seed=seed,core=core,snapshot=snapshot,worker=instance,feed=feed)

    def test_actual_baseline60_allocates61_and_builds_one_accepted_child_candidate(self):
        fixture=self.fixture(60)
        sequence=fixture.worker.next_counter()
        self.assertEqual(sequence,61)
        self.assertEqual(json.loads(fixture.worker.state_file.read_bytes())['counter'],61)
        result=source.prepare_candidate(fixture.checkout,fixture.snapshot,sequence)
        self.assertEqual(result['version'],'0.4.4-preview.61')
        self.assertFalse(result['reused'])
        self.assertEqual(policy.parents(fixture.checkout,result['commit']),[fixture.seed])
        candidate=json.loads((fixture.checkout/source.CANDIDATE_PATH).read_bytes())
        self.assertEqual(candidate['baseRevision'],fixture.seed)
        self.assertEqual(candidate['versionSequence'],61)
        allowed=policy.validate_candidate_delta(fixture.checkout,result['commit'],fixture.seed,candidate)
        self.assertEqual(allowed,set(candidate['inventory'])|set(policy.VERSION_FILES)|{policy.CANDIDATE})
        for name in policy.VERSION_FILES:
            self.assertEqual(json.loads((fixture.checkout/name).read_bytes())['version'],'0.4.4-preview.61')
        self.assertEqual((fixture.checkout/'app/captured.php').read_bytes(),b'<?php // new captured feature\n')
        retry=source.prepare_candidate(fixture.checkout,fixture.snapshot,sequence)
        self.assertTrue(retry['reused']);self.assertEqual(retry['commit'],result['commit'])
        self.assertEqual(json.loads(fixture.worker.state_file.read_bytes())['counter'],61)

    def test_review_manifest61_would_allocate62_and_cannot_be_forced_to_candidate61(self):
        fixture=self.fixture(61)
        self.assertEqual(fixture.worker.next_counter(),62)
        with self.assertRaisesRegex(source.SourceError,'VERSION_NOT_ADVANCED'):
            source.prepare_candidate(fixture.checkout,fixture.snapshot,61)
        self.assertEqual(source._git(fixture.checkout,['rev-parse','HEAD']).decode().strip(),fixture.seed)

    def test_original_allocator_preserves_durable_published_and_version_floors(self):
        fixture=self.fixture(60)
        for counter,floor,published,expected in [(65,60,60,66),(60,60,66,67),(60,67,60,68)]:
            fixture.worker.state.update(counter=counter,version_floor='0.4.4-preview.'+str(floor))
            self.write(fixture.feed,{'version':'0.4.4-preview.'+str(published)})
            with self.subTest(counter=counter,floor=floor,published=published):
                self.assertEqual(fixture.worker.next_counter(),expected)
                self.assertEqual(json.loads(fixture.worker.state_file.read_bytes())['counter'],expected)

    def test_manifest_and_each_lock_version_are_rejected_before_allocation_or_effects(self):
        # The actual coordinator's pre-effect baseline guard runs here. Policy
        # lineage is separately exercised above and in the frozen stage tests.
        for field in ('package','lock','lock_root'):
            fixture=self.fixture(60)
            if field=='package':self.write(fixture.checkout/'desktop-pos/package.json',{'version':'0.4.4-preview.61'})
            else:
                lock=json.loads((fixture.checkout/'desktop-pos/package-lock.json').read_bytes())
                if field=='lock':lock['version']='0.4.4-preview.61'
                else:lock['packages']['']['version']='0.4.4-preview.61'
                self.write(fixture.checkout/'desktop-pos/package-lock.json',lock)
            instance=object.__new__(rollout.Rollout)
            def file(name,revision=None):
                if name=='deployment/verify_go_erp_preview31_seed.py':return b'authenticated policy fixture'
                return (fixture.checkout/name).read_bytes()
            def forbidden(*_args,**_kwargs):raise AssertionError('Allocation or effects reached')
            instance.package=SimpleNamespace(file=file,repository=fixture.checkout,git=forbidden)
            instance.root_api=SimpleNamespace(load=lambda *_args:SimpleNamespace(CORE=rollout.CORE,
                validate_seed=lambda *_args,**_kwargs:{'core':rollout.CORE,'seed':rollout.SEED}))
            instance.io=SimpleNamespace(parse=json.loads)
            instance.current=SimpleNamespace(VERSION='0.4.4-preview.60')
            instance.e=SimpleNamespace(atomic_write=forbidden,run=forbidden)
            with self.subTest(field=field),self.assertRaisesRegex(rollout.Stop,'POLICY_SEED_VERSION_BASELINE_CHANGED'):
                instance._reviewed_code()
            self.assertFalse(fixture.worker.state_file.exists())
            self.assertEqual(fixture.worker.state['counter'],60)

    def test_the_executed_counter_method_is_the_unchanged_pinned_public_body(self):
        raw=(FIXTURES/'worker_publisher.py').read_text()
        klass=next(node for node in ast.parse(raw).body if isinstance(node,ast.ClassDef) and node.name=='Worker')
        method=next(node for node in klass.body if isinstance(node,ast.FunctionDef) and node.name=='next_counter')
        exact=ast.get_source_segment(raw,method).encode()
        self.assertEqual(hashlib.sha256(exact).hexdigest(),
                         '2092c0626df47d900e73ae4a678663a09cdbf4924a23538bb8b2eeb075e85a67')


if __name__=='__main__':unittest.main()
