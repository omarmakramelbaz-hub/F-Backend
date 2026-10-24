"""Real pinned publisher checks guard atomic current/previous download archives."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


subject = load('published_downloads_subject', ROOT / 'deployment/go_erp_published_download_root.py')
proof = load('published_downloads_frozen_fixture', ROOT / 'tests/test_go_erp_preview42_restoration.py')
publisher = proof.frozen_module('published_downloads_actual_publisher', proof.PAYLOADS['publish_go_erp_update.py'])


class PublishedDownloadTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='go-erp-published-downloads-')
        self.addCleanup(temporary.cleanup)
        self.path = Path(temporary.name)
        self.root = self.path / 'application'
        self.native = self.root / 'storage/app/desktop-updates'
        self.native.mkdir(parents=True, mode=0o755)
        self.state_path = self.path / 'native-state.json'
        self.shared = self.path / 'shared'
        self.shared.mkdir(mode=0o750)
        self.manifest = self.shared / 'downloads.json'
        self.history=[]
        self.state={}
        self.published=self.publish(42)
        patched=patch.object(subject,'load_publisher',return_value=publisher)
        patched.start();self.addCleanup(patched.stop)

    def write(self,path,value,mode=0o600):
        path.write_bytes(subject.canonical(value));path.chmod(mode)

    def publish(self,number,feed=True):
        body=b'MZverified-installer-'+str(number).encode()
        digest=hashlib.sha256(body).hexdigest()
        release={'version':'0.4.4-preview.'+str(number),'sequence':40000000000+number,
            'source_revision':format(number,'040x'),
            'source':{'format':1,'files':1355,'framework':'8.83.29','sha256':format(number,'064x')},
            'installer':{'sha256':digest,'bytes':len(body),
                'path':'/api/desktop-dashboard/update/installer/'+digest},'gui':{}}
        publisher.validate_release(release)
        binary=self.native/(digest+'.exe');binary.write_bytes(body);binary.chmod(0o644)
        self.history.append(release)
        self.state={'format':1,'counter':number+1,'version_floor':release['version'],
            'sequence_floor':release['sequence'],'published_history':self.history.copy(),
            'desired':{'commit':'f'*40,'status':'waiting-ci'},
            'published':{'commit':release['source_revision'],'inventorySha256':'b'*64,
                'run_id':40000000000+number,'run_attempt':1,'release':release}}
        self.write(self.state_path,self.state)
        if feed:self.write(self.native/'current.json',release,0o644)
        else:(self.native/'current.json').unlink(missing_ok=True)
        return release

    def archive(self):
        return subject.archive_current(self.root,self.state_path,self.shared,os.getgid())

    def current(self):return json.loads(self.manifest.read_bytes())

    def test_initial42_is_separate_immutable_copy_with_root_web_metadata(self):
        result=self.archive();value=self.current()
        self.assertTrue(result['changed']);self.assertTrue(result['available'])
        self.assertEqual(value,{'format':1,'kind':subject.KIND,'current':self.published,'previous':None})
        target=self.shared/'installers'/(self.published['installer']['sha256']+'.exe')
        source=self.native/target.name
        self.assertNotEqual(target.stat().st_ino,source.stat().st_ino)
        self.assertEqual(target.read_bytes(),source.read_bytes())
        for path,mode in [(self.manifest,0o640),(target,0o640),(target.parent,0o750)]:
            info=path.stat();self.assertEqual((info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode)),(0,os.getgid(),mode))
        source.unlink()
        self.assertTrue(target.is_file())

    def test_missing_canonical_feed_bootstraps_real_published42_without_source_equality(self):
        (self.native/'current.json').unlink()
        (self.root/'new-source.php').write_text('<?php // dashboard changed during next build')
        before=self.state_path.read_bytes()
        result=self.archive()
        self.assertEqual(result['source'],'published-state')
        self.assertEqual(self.current()['current'],self.published)
        self.assertFalse((self.native/'current.json').exists())
        self.assertEqual(self.state_path.read_bytes(),before)

    def test_rotation_keeps_previous_and_same_release_never_rotates(self):
        self.archive();old=self.current()['current']
        next_release=self.publish(44);self.archive()
        self.assertEqual((self.current()['current'],self.current()['previous']),(next_release,old))
        raw=self.manifest.read_bytes();info=self.manifest.stat()
        self.assertFalse(self.archive()['changed'])
        self.assertEqual((self.manifest.read_bytes(),self.manifest.stat().st_ino),(raw,info.st_ino))
        newer=self.publish(45);self.archive()
        self.assertEqual((self.current()['current'],self.current()['previous']),(newer,next_release))
        # An installer opened from an earlier generation survives native pruning
        # and pointer rotation because the archive has a distinct durable inode.
        self.assertTrue((self.shared/'installers'/(old['installer']['sha256']+'.exe')).is_file())

    def test_older_or_mismatched_canonical_publication_cannot_replace_pointers(self):
        self.archive();self.publish(44);self.archive();before=self.manifest.read_bytes()
        self.publish(42)
        with self.assertRaisesRegex(subject.Stop,'OLDER_OR_CONFLICTING'):self.archive()
        self.assertEqual(self.manifest.read_bytes(),before)
        self.write(self.native/'current.json',self.history[1],0o644)
        with self.assertRaisesRegex(subject.Stop,'CANONICAL_STATE_MISMATCH'):self.archive()
        self.assertEqual(self.manifest.read_bytes(),before)

    def test_unbound_state_bad_installer_and_native_prune_do_not_manufacture_archive(self):
        self.state['published']['commit']='f'*40;self.write(self.state_path,self.state)
        with self.assertRaisesRegex(subject.Stop,'PUBLISHED_IDENTITY_INVALID'):self.archive()
        self.assertFalse(self.manifest.exists())
        release=self.publish(44)
        (self.native/(release['installer']['sha256']+'.exe')).write_bytes(b'MZcorrupt-bytes')
        with self.assertRaises(subject.Stop):self.archive()
        self.assertFalse(self.manifest.exists())

    def test_state_or_feed_change_during_copy_leaves_previous_generation_active(self):
        self.archive();before=self.manifest.read_bytes();self.publish(44)
        original=subject.copy_installer
        def race(*args):
            original(*args)
            state=json.loads(self.state_path.read_bytes());state['counter']+=1
            self.write(self.state_path,state)
        with patch.object(subject,'copy_installer',side_effect=race):
            with self.assertRaisesRegex(subject.Stop,'NATIVE_STATE_CHANGED'):self.archive()
        self.assertEqual(self.manifest.read_bytes(),before)
        def feed_race(*args):
            original(*args);(self.native/'current.json').unlink()
        with patch.object(subject,'copy_installer',side_effect=feed_race):
            with self.assertRaisesRegex(subject.Stop,'CANONICAL_FEED_CHANGED'):self.archive()
        self.assertEqual(self.manifest.read_bytes(),before)

    def test_duplicate_fields_size_limit_and_archive_symlinks_are_refused(self):
        self.manifest.write_text('{"format":1,"format":1}');self.manifest.chmod(0o640)
        with self.assertRaisesRegex(subject.Stop,'JSON_INVALID'):self.archive()
        self.manifest.write_bytes(b' '*(subject.MAX_MANIFEST+1))
        with self.assertRaisesRegex(subject.Stop,'FILE_UNSAFE'):self.archive()
        self.manifest.unlink();outside=self.path/'outside';outside.write_text('PRIVATE_SENTINEL')
        self.manifest.symlink_to(outside)
        with self.assertRaises(subject.Stop):self.archive()
        self.assertEqual(outside.read_text(),'PRIVATE_SENTINEL')

    def test_actual_publisher_pin_loader_rejects_changed_bytes(self):
        folder=self.path/'publisher';folder.mkdir(mode=0o755)
        file=folder/'publish_go_erp_update.py';file.write_bytes(proof.PAYLOADS[file.name]);file.chmod(0o644)
        # Bypass only setUp's test injection to exercise the exact byte loader.
        loader=load('published_downloads_original_loader',ROOT/'deployment/go_erp_published_download_root.py')
        self.assertEqual(loader.load_publisher(file).MAX_BYTES,publisher.MAX_BYTES)
        file.write_bytes(file.read_bytes()+b'\n')
        with self.assertRaisesRegex(loader.Stop,'PUBLISHER_PIN_INVALID'):loader.load_publisher(file)

    def test_real_broker_archives42_before_tick_and_promotes44_before_ready(self):
        broker_module=load('published_downloads_real_broker',ROOT/'deployment/go_erp_manual_updates_root.py')
        control=self.path/'control';control.mkdir(mode=0o755)
        for name,mode in [('private',0o700),('private/jobs',0o700),('requests',0o700),('shared',0o750)]:
            (control/name).mkdir(mode=mode)
        self.shared=control/'shared';self.manifest=self.shared/'downloads.json'
        (control/'requests/request.lock').touch(mode=0o600)
        nonce='a'*32;now=[1000000];events=[]
        self.write(control/'requests/pending.json',{'format':1,'kind':'go-erp-manual-release-request',
            'nonce':nonce,'actor_id':1,'requested_at':now[0]})
        self.state['counter']=43
        self.state['desired']={'commit':'f'*40,'inventorySha256':'b'*64,
            'version':'0.4.4-preview.43','status':'blocked'}
        self.write(self.state_path,self.state)
        def archive(base,gid):
            value=self.archive();events.append(('archive',value['current_version']));return value
        def tick(job):
            self.assertTrue(job['_allow_prepare'])
            self.assertEqual(self.current()['current']['version'],'0.4.4-preview.42')
            events.append(('tick',None))
            release=self.publish(44)
            self.state['counter']=44
            self.state['desired']={'commit':release['source_revision'],'inventorySha256':'b'*64,
                'version':release['version'],'status':'published'}
            self.write(self.state_path,self.state)
            return 0,'GO_ERP_LIVE_UPDATE_PUBLISHED'
        broker=broker_module.Broker(control,web_uid=0,web_gid=os.getgid(),clock=lambda:now[0],
            sleep=lambda delay:now.__setitem__(0,now[0]+delay),tick=tick,
            snapshot=lambda:broker_module.native_snapshot(self.state_path,self.native/'current.json'),archive=archive)
        broker.run()
        self.assertEqual(events,[('archive','0.4.4-preview.42'),('tick',None),('archive','0.4.4-preview.44')])
        self.assertEqual(self.current()['previous']['version'],'0.4.4-preview.42')
        self.assertEqual(json.loads((self.shared/'status.json').read_bytes())['state'],'ready')


if __name__=='__main__':unittest.main()
