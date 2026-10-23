"""Exercise the complete server-only unpaid-order source publication and rollback."""
import hashlib
import importlib.util
import io
import os
from contextlib import redirect_stdout
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('unpaid_publisher',ROOT/'deployment/apply_server_dashboard_refinements_root.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class UnpaidPublisherTests(unittest.TestCase):
 def setUp(self):
  t=tempfile.TemporaryDirectory();self.addCleanup(t.cleanup);self.root=Path(t.name)/'app';self.root.mkdir();self.backup=Path(t.name)/'backup';self.calls=[]
  self.new={n:(ROOT/n).read_bytes() for n in m.PINS};self.old={n:('prior '+n).encode() for n,p in m.PINS.items() if p[0] is not None}
  for n in m.PINS:self.assertEqual(hashlib.sha256(self.new[n]).hexdigest(),m.PINS[n][1])
  pins={n:(hashlib.sha256(self.old[n]).hexdigest() if n in self.old else None,p[1]) for n,p in m.PINS.items()};p=patch.object(m,'PINS',pins);p.start();self.addCleanup(p.stop)
  self.engine=m.engine((ROOT/'deployment/apply_paymob_order_confirmation_root.py').read_bytes())
  for n in m.PINS:
   target=self.root/n;target.parent.mkdir(parents=True,exist_ok=True)
   if n in self.old:target.write_bytes(self.old[n]);target.chmod(0o640)
 def runner(self,args,**kwargs):
  self.calls.append(args)
  if args[:3]==[m.PHP,'-n','-l']:self.assertIn(Path(args[3]).read_bytes(),self.new.values())
  else:
   self.assertEqual(args[-1],'view:clear')
   for n in self.new:self.assertEqual((self.root/n).read_bytes(),self.new[n])
  return types.SimpleNamespace(returncode=0)
 def publish(self):
  with redirect_stdout(io.StringIO()):return m.publish_package(self.root,self.engine,self.new,self.backup,os.getuid(),os.getgid(),self.runner)
 def test_dependencies_and_views_publish_before_controller_with_lint_and_cache_clear(self):
  self.assertTrue(self.publish());self.assertEqual(sum(a[:3]==[m.PHP,'-n','-l'] for a in self.calls),sum(n.endswith('.php') for n in m.PINS));self.assertEqual(self.calls[-1][-1],'view:clear')
  for n in self.old:self.assertEqual((self.backup/n).read_bytes(),self.old[n])
  self.assertFalse(self.publish())
 def test_failed_final_controller_write_rolls_back_and_removes_new_dependency(self):
  def replace(target,*args):
   if target==self.root/m.CONTROLLER:raise OSError('injected failure')
   return m.cache_replace(target,*args)
  with patch.object(self.engine,'replace_file',side_effect=replace):
   with self.assertRaises(OSError):self.publish()
  for n in m.PINS:
   if n in self.old:self.assertEqual((self.root/n).read_bytes(),self.old[n])
   else:self.assertFalse((self.root/n).exists())
  self.assertFalse(any(a[-1]=='view:clear' for a in self.calls))
 def test_unknown_live_edits_refuse_before_lint_or_publication(self):
  (self.root/m.CONTROLLER).write_bytes(b'unknown live edit')
  with self.assertRaisesRegex(RuntimeError,'^BASELINE_MISMATCH$'):self.publish()
  self.assertFalse(self.calls);self.assertFalse(self.backup.exists())
if __name__=='__main__':unittest.main()
