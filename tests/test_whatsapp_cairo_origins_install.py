#!/usr/bin/env python3
"""Scoped Cairo publication, unchanged settings and dependency-safe recovery."""
import fcntl
import importlib.util
import json
import os
import pathlib
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = pathlib.Path(__file__).absolute().parent.parent
spec = importlib.util.spec_from_file_location('cairo_installer', ROOT / 'deployment/apply_whatsapp_cairo_origins.py')
fix = importlib.util.module_from_spec(spec); sys.modules[spec.name] = fix; spec.loader.exec_module(fix)


class CairoInstallTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(); self.addCleanup(self.temporary.cleanup)
        self.parent = pathlib.Path(self.temporary.name)
        self.root = self.parent / 'public_html'; self.root.mkdir(); (self.root / '.git').mkdir()
        self.payload = self.parent / 'payload'; (self.payload / 'deployment').mkdir(parents=True)
        self.uid = os.geteuid(); self.originals = {}; self.targets = {}; pins = {}
        for index, relative in enumerate(fix.SOURCE_PINS):
            old = None if relative == fix.ORIGIN_PATH else ('<?php // old-'+str(index)).encode()
            new = ('<?php // new-'+str(index)).encode()
            if old is not None:
                self.write(self.root / relative, old)
            self.write(self.payload / relative, new)
            self.originals[relative] = old; self.targets[relative] = new
            pins[relative] = (fix.blob(old) if old is not None else None, fix.blob(new))
        patches = patch.dict(fix.SOURCE_PINS,pins,clear=True); patches.start(); self.addCleanup(patches.stop)
        self.protected = {}
        for relative in dict.fromkeys((*fix.PROTECTED_PATHS,*fix.PROTECTED_BLOBS)):
            data = b'unchanged-configuration-activation-floor-4520; mappings; tokens; '+relative.encode()
            self.write(self.root / relative,data,0o600 if relative=='.env' else 0o644)
            self.protected[relative] = (data,fix.metadata(self.root / relative))
        patches=patch.dict(fix.PROTECTED_BLOBS,{relative:fix.blob(self.protected[relative][0])for relative in fix.PROTECTED_BLOBS},clear=True)
        patches.start();self.addCleanup(patches.stop)
        self.manifest = self.payload / 'deployment/whatsapp_cairo_origins_manifest.json'
        self.manifest_data = {'version':1,'sources':[
            {'path':relative,'before_blob':old,'after_blob':new}for relative,(old,new)in pins.items()]}
        self.rewrite_manifest(); self.mode = 'review'; self.calls = []

    def write(self,path,data,mode=0o644):
        path.parent.mkdir(parents=True,exist_ok=True); path.write_bytes(data); path.chmod(mode)

    def rewrite_manifest(self):
        self.write(self.manifest,json.dumps(self.manifest_data).encode())
        self.digest = fix.blob(self.manifest.read_bytes())

    def runner(self,root,args,capture=False,**kwargs):
        self.calls.append(args)
        if args[0]=='/usr/bin/systemctl':
            return 0,b'LoadState=loaded\nActiveState=inactive\n'+(b'MainPID=0\n' if args[-1].endswith('.service') else b'')
        if len(args)>2 and args[1:3]==['-r',fix.READINESS_PHP]:
            return 0,json.dumps({'mode':self.mode,'config_fingerprint':'a'*64}).encode()
        return 0,b''

    def apply(self,runner=None):
        return fix.apply(self.root,self.uid,self.manifest,self.digest,runner=runner or self.runner)

    def assert_original_sources(self):
        for relative,original in self.originals.items():
            if original is None:
                self.assertFalse((self.root / relative).exists())
            else:
                self.assertEqual((self.root / relative).read_bytes(),original)

    def assert_protected(self):
        for relative,(content,metadata)in self.protected.items():
            self.assertEqual((self.root / relative).read_bytes(),content)
            self.assertEqual(fix.metadata(self.root / relative),metadata)

    def test_publish_scope_private_backups_and_untouched_configuration(self):
        status,backup = self.apply()
        self.assertEqual(status,'WHATSAPP_CAIRO_DELIVERY_ORIGINS_READY')
        for relative,target in self.targets.items():
            self.assertEqual((self.root / relative).read_bytes(),target)
        self.assert_protected()
        self.assertEqual(backup.stat().st_mode & 0o777,0o700)
        receipt = json.loads((backup / 'receipt.json').read_bytes())
        self.assertEqual(set(receipt['files']),set(self.targets))
        self.assertEqual(receipt['mode_preserved'],'review')
        self.assertEqual(receipt['effective_configuration_fingerprint'],'a'*64)
        self.assertEqual(receipt['files'][fix.ORIGIN_PATH]['before_blob'],None)
        self.assertEqual((backup / 'receipt.json').stat().st_mode & 0o777,0o600)
        self.assertEqual([args[3]for args in self.calls if len(args)>2 and args[1:3]==['-r',fix.READINESS_PHP]],['source','target'])

    def test_existing_auto_preserves_cutoffs_and_mappings(self):
        self.mode = 'auto'; _,backup = self.apply()
        self.assertEqual(json.loads((backup / 'receipt.json').read_bytes())['mode_preserved'],'auto')
        self.assert_protected()
        self.assertTrue(all(args[0] in (fix.PHP,'/usr/bin/systemctl') or '--check' in args for args in self.calls))
        self.assertFalse(any('artisan' in args or any('quote'==arg or 'process-orders'==arg for arg in args)for args in self.calls))

    def test_idempotency_keeps_all_published_inodes(self):
        self.apply(); inodes = {relative:(self.root / relative).stat().st_ino for relative in self.targets}
        self.apply()
        self.assertEqual(inodes,{relative:(self.root / relative).stat().st_ino for relative in self.targets})
        self.assert_protected()

    def test_hardlinked_live_source_retains_peer_then_rollback_restores_inode(self):
        live = self.root / fix.PHONE_PATH; peer = self.parent / 'retained-release-source'
        os.link(live,peer); inode = live.stat().st_ino
        self.apply(); self.assertNotEqual(live.stat().st_ino,inode)
        self.assertEqual(peer.stat().st_ino,inode); self.assertEqual(peer.read_bytes(),self.originals[fix.PHONE_PATH])
        # Fresh fixture verifies atomic rollback returns the original multiply linked inode.
        live.unlink(); os.link(peer,live); rollback_links=live.stat().st_nlink
        for relative,old in self.originals.items():
            if relative==fix.PHONE_PATH:continue
            path=self.root / relative
            if old is None:path.unlink()
            else:self.write(path,old)
        def failed(root,args,**kwargs):
            if args[1:3]==['-r',fix.READINESS_PHP] and args[3]=='target':return 1,b''
            return self.runner(root,args,**kwargs)
        with self.assertRaisesRegex(fix.SafeError,'FILES_RESTORED'):self.apply(failed)
        self.assertEqual(live.stat().st_ino,inode); self.assertEqual(live.stat().st_nlink,rollback_links)
        self.assertEqual(peer.read_bytes(),self.originals[fix.PHONE_PATH]); self.assert_original_sources()

    def test_unknown_live_hash_refuses_all_publication(self):
        self.write(self.root / fix.PHONE_PATH,b'unknown-live-change')
        with self.assertRaisesRegex(fix.SafeError,'LIVE_HASH_NEEDS_REVIEW'):self.apply()
        self.assertFalse((self.root / fix.ORIGIN_PATH).exists())
        self.assertEqual((self.root / fix.PHONE_PATH).read_bytes(),b'unknown-live-change'); self.assert_protected()

    def test_manifest_scope_and_pin_checks_cannot_authorize_environment_write(self):
        self.manifest_data['sources'].append({'path':'.env','before_blob':'a'*40,'after_blob':'b'*40}); self.rewrite_manifest()
        with self.assertRaisesRegex(fix.SafeError,'MANIFEST_SCOPE_INVALID'):self.apply()
        self.assert_original_sources(); self.assert_protected()
        self.manifest_data['sources'].pop(); self.rewrite_manifest()
        with self.assertRaisesRegex(fix.SafeError,'MANIFEST_HASH_MISMATCH'):
            fix.apply(self.root,self.uid,self.manifest,'0'*40,runner=self.runner)

    def test_changed_or_hardlinked_payload_refuses_before_publication(self):
        target=self.payload / fix.PHONE_PATH; self.write(target,b'tampered-payload')
        with self.assertRaisesRegex(fix.SafeError,'PAYLOAD_HASH_MISMATCH'):self.apply()
        self.write(target,self.targets[fix.PHONE_PATH]); os.link(target,self.payload / 'payload-peer')
        with self.assertRaisesRegex(fix.SafeError,'PAYLOAD_LINK_UNSAFE'):self.apply()
        self.assert_original_sources()

    def test_symlink_or_group_writable_live_file_is_refused(self):
        live=self.root / fix.PHONE_PATH; live.chmod(0o664)
        with self.assertRaisesRegex(fix.SafeError,'MODE_OR_PAYLOAD_LINK_UNSAFE'):self.apply()
        live.chmod(0o644); peer=self.parent / 'symlink-peer'; self.write(peer,live.read_bytes()); live.unlink(); live.symlink_to(peer)
        with self.assertRaises(fix.SafeError):self.apply()
        self.assertFalse((self.root / fix.ORIGIN_PATH).exists())

    def test_candidate_lint_failure_never_publishes_any_file(self):
        def failed(root,args,**kwargs):
            if '-l' in args:return 1,b''
            return self.runner(root,args,**kwargs)
        with self.assertRaisesRegex(fix.SafeError,'CANDIDATE_LINT_OR_STAGING_FAILED'):self.apply(failed)
        self.assert_original_sources(); self.assert_protected()

    def test_node_absence_is_supported_without_skipping_php_checks(self):
        with patch('shutil.which',return_value=None):self.apply()
        self.assertEqual(sum('-l' in args for args in self.calls),6)
        self.assert_protected()

    def test_source_schema_or_mode_readiness_failure_is_read_only(self):
        def failed(root,args,**kwargs):
            if args[1:3]==['-r',fix.READINESS_PHP]:return 1,b'never-output-this-secret'
            return self.runner(root,args,**kwargs)
        with self.assertRaisesRegex(fix.SafeError,'READ_ONLY_READINESS_FAILED')as caught:self.apply(failed)
        self.assertNotIn('secret',str(caught.exception)); self.assert_original_sources(); self.assert_protected()

    def test_postflight_failure_restores_sources_and_removes_new_class(self):
        def failed(root,args,**kwargs):
            if args[1:3]==['-r',fix.READINESS_PHP] and args[3]=='target':return 1,b''
            return self.runner(root,args,**kwargs)
        with self.assertRaisesRegex(fix.SafeError,'FILES_RESTORED'):self.apply(failed)
        self.assert_original_sources(); self.assert_protected()

    def test_effective_configuration_or_mode_drift_rolls_back_source_files(self):
        for target in ({'mode':'auto','config_fingerprint':'a'*64},{'mode':'review','config_fingerprint':'b'*64}):
            def drift(root,args,**kwargs):
                if args[1:3]==['-r',fix.READINESS_PHP] and args[3]=='target':return 0,json.dumps(target).encode()
                return self.runner(root,args,**kwargs)
            with self.assertRaisesRegex(fix.SafeError,'FILES_RESTORED'):self.apply(drift)
            self.assert_original_sources(); self.assert_protected()

    def test_protected_concurrent_edit_is_retained_and_sources_restored(self):
        path=self.root / 'config/whatsapp_cart.php'; peer=b'new-live-mappings-must-stay'
        def changed(root,args,**kwargs):
            if args[1:3]==['-r',fix.READINESS_PHP] and args[3]=='target':self.write(path,peer)
            return self.runner(root,args,**kwargs)
        with self.assertRaisesRegex(fix.SafeError,'FILES_RESTORED'):self.apply(changed)
        self.assertEqual(path.read_bytes(),peer); self.assert_original_sources()

    def test_retained_concurrent_workflow_keeps_every_origin_dependency(self):
        peer=b'newer-workflow-needs-FasakhanstaDeliveryOrigin'
        def changed(root,args,**kwargs):
            if args[1:3]==['-r',fix.READINESS_PHP] and args[3]=='target':
                self.write(self.root / fix.WORKFLOW_PATH,peer);return 1,b''
            return self.runner(root,args,**kwargs)
        with self.assertRaisesRegex(fix.SafeError,'ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):self.apply(changed)
        self.assertEqual((self.root / fix.WORKFLOW_PATH).read_bytes(),peer)
        for relative,target in self.targets.items():
            if relative!=fix.WORKFLOW_PATH:self.assertEqual((self.root / relative).read_bytes(),target)
        self.assert_protected()

    def test_retained_mid_dependency_restores_later_entrypoints_but_keeps_origin(self):
        peer=b'newer-PhoneDelivery-needs-FasakhanstaDeliveryOrigin'
        def changed(root,args,**kwargs):
            if args[1:3]==['-r',fix.READINESS_PHP] and args[3]=='target':
                self.write(self.root / fix.PHONE_PATH,peer);return 1,b''
            return self.runner(root,args,**kwargs)
        with self.assertRaisesRegex(fix.SafeError,'ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):self.apply(changed)
        self.assertEqual((self.root / fix.PHONE_PATH).read_bytes(),peer)
        self.assertEqual((self.root / fix.ORIGIN_PATH).read_bytes(),self.targets[fix.ORIGIN_PATH])
        for relative in (fix.POS_PATH,fix.CONTROLLER_PATH,fix.UI_PATH,fix.WORKFLOW_PATH):
            self.assertEqual((self.root / relative).read_bytes(),self.originals[relative])

    def test_interrupted_atomic_exchange_is_recovered_from_preexisting_journal(self):
        original=fix.base.atomic_exchange; count=[0]
        def interrupted(a,b):
            original(a,b)
            if b==self.root / fix.PHONE_PATH and count[0]==0:
                count[0]+=1;raise RuntimeError('injected-after-syscall')
        with patch.object(fix.base,'atomic_exchange',interrupted):
            with self.assertRaisesRegex(fix.SafeError,'FILES_RESTORED'):self.apply()
        self.assert_original_sources(); self.assert_protected()

    def test_active_or_still_running_worker_blocks_publication(self):
        for report in (b'LoadState=loaded\nActiveState=active\nMainPID=0\n',
                       b'LoadState=loaded\nActiveState=inactive\nMainPID=999\n',
                       b'LoadState=loaded\nActiveState=deactivating\nMainPID=999\n'):
            def busy(root,args,**kwargs):
                if args[0]=='/usr/bin/systemctl':return 0,report
                return self.runner(root,args,**kwargs)
            with self.assertRaisesRegex(fix.SafeError,'WORKERS_MUST_BE_PAUSED'):self.apply(busy)
            self.assert_original_sources(); self.assert_protected()

    def test_worker_restarting_during_lint_refuses_before_publication(self):
        self.worker_restarted=False
        def race(root,args,**kwargs):
            if '-l' in args:self.worker_restarted=True
            if args[0]=='/usr/bin/systemctl' and self.worker_restarted:
                return 0,b'LoadState=loaded\nActiveState=active\nMainPID=0\n'
            return self.runner(root,args,**kwargs)
        with self.assertRaisesRegex(fix.SafeError,'FILES_RESTORED'):self.apply(race)
        self.assert_original_sources(); self.assert_protected()

    def test_known_legacy_protected_models_keep_original_mode_and_bytes(self):
        area=self.root / 'app/Models/Area.php'; area.chmod(0o775)
        model=self.root / 'app/Models/Resturant.php'; model.chmod(0o755)
        for relative in fix.LEGACY_READ_ONLY_MODELS:
            self.protected[relative]=(self.protected[relative][0],fix.metadata(self.root / relative))
        self.apply(); self.assert_protected()
        self.assertEqual(area.stat().st_mode & 0o777,0o775)
        self.assertEqual(model.stat().st_mode & 0o777,0o755)

    def test_unknown_protected_runtime_profile_blocks_whole_upgrade(self):
        path=self.root / 'app/Services/Dashboard/PhoneMapProvider.php'
        self.write(path,b'new-unreviewed-map-runtime')
        with self.assertRaisesRegex(fix.SafeError,'PROTECTED_SOURCE_PROFILE_CHANGED'):self.apply()
        self.assertEqual(path.read_bytes(),b'new-unreviewed-map-runtime')
        self.assert_original_sources()

    def test_lock_contention_prevents_any_publication(self):
        parent=self.root.parent / 'whatsapp-release-backups'; parent.mkdir(mode=0o700)
        path=parent / 'orders-install.lock'; descriptor=os.open(path,os.O_RDWR|os.O_CREAT,0o600)
        try:
            fcntl.flock(descriptor,fcntl.LOCK_EX|fcntl.LOCK_NB)
            with self.assertRaisesRegex(fix.SafeError,'ANOTHER_INSTALL_OR_CONFIGURATION'):self.apply()
        finally:os.close(descriptor)
        self.assert_original_sources(); self.assert_protected()


if __name__=='__main__':
    unittest.main()
