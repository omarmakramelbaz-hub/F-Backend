"""Verify the server-only two-asset release against its exact shipped pins."""
import importlib.util
from contextlib import redirect_stdout
import io
import hashlib
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('responsive_publisher',ROOT/'deployment/apply_home_hud_responsive_root.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class ResponsivePublicationTests(unittest.TestCase):
 def setUp(self):
  temp=tempfile.TemporaryDirectory();self.addCleanup(temp.cleanup);self.root=Path(temp.name)/'app';self.root.mkdir();self.backup=Path(temp.name)/'backup'
  self.old={n:('prior fixture '+n).encode() for n in m.PINS};self.new={n:(ROOT/n).read_bytes() for n in m.PINS}
  for n in m.PINS:self.assertEqual(hashlib.sha256(self.new[n]).hexdigest(),m.PINS[n][1])
  pin_patch=patch.object(m,'PINS',{n:(hashlib.sha256(self.old[n]).hexdigest(),m.PINS[n][1]) for n in m.PINS});pin_patch.start();self.addCleanup(pin_patch.stop)
  self.engine=m.engine((ROOT/'deployment/apply_paymob_order_confirmation_root.py').read_bytes())
  for n,data in self.old.items():p=self.root/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data);p.chmod(0o640)
 def publish(self):
  with redirect_stdout(io.StringIO()):return m.publish_package(self.root,self.engine,self.new,self.backup,os.getuid(),os.getgid())
 def test_exact_two_assets_publish_with_backup_and_fresh_versions_then_retry_is_noop(self):
  old_times={n:(self.root/n).stat().st_mtime_ns for n in m.PINS};self.assertTrue(self.publish())
  for n in m.PINS:
   self.assertEqual((self.root/n).read_bytes(),self.new[n]);self.assertEqual((self.backup/n).read_bytes(),self.old[n]);self.assertEqual((self.root/n).stat().st_mode&0o777,0o640);self.assertGreater((self.root/n).stat().st_mtime_ns//10**9,old_times[n]//10**9)
  self.assertFalse(self.publish())
 def test_failure_on_second_asset_restores_both_original_assets(self):
  def fail(target,*args):
   if target==self.root/m.LAST_PATH:raise OSError('injected')
   return m.cache_replace(target,*args)
  with patch.object(self.engine,'replace_file',side_effect=fail):
   with self.assertRaises(OSError):self.publish()
  for n in m.PINS:self.assertEqual((self.root/n).read_bytes(),self.old[n])
 def test_unknown_live_edits_are_preserved_and_refused(self):
  p=self.root/m.LAST_PATH;p.write_bytes(b'concurrent edit')
  with self.assertRaisesRegex(RuntimeError,'^BASELINE_MISMATCH$'):self.publish()
  self.assertEqual(p.read_bytes(),b'concurrent edit');self.assertFalse(self.backup.exists())
if __name__=='__main__':unittest.main()
