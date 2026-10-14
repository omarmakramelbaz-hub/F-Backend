import contextlib, hashlib, importlib.util, io, os, pathlib, tempfile, types, unittest
from unittest.mock import patch

spec=importlib.util.spec_from_file_location('hub',pathlib.Path(__file__).parents[1]/'deployment/update_meta_message_hub.py')
hub=importlib.util.module_from_spec(spec);spec.loader.exec_module(hub)

class MessageHubInstallTests(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory();self.base=pathlib.Path(self.temp.name);self.root=self.base/'app';self.root.mkdir()
  self.old=b'<?php // original source\n';self.new=b'<?php // reviewed source\n';self.rel='app/Controller.php';self.path=self.root/self.rel;self.path.parent.mkdir();self.path.write_bytes(self.old)
  self.menu=self.root/'resources/views/admin/layouts/menu.blade.php';self.menu.parent.mkdir(parents=True);self.menu.write_text("native menu\n@include('admin.whatsapp.sidebar')\n@include('admin.meta.sidebar')\nnative tail\n")
  self.menuBefore=self.menu.read_bytes();self.calls=[]
  self.user=types.SimpleNamespace(pw_uid=os.getuid(),pw_gid=os.getgid())
  self.stack=contextlib.ExitStack();self.addCleanup(self.stack.close);self.addCleanup(self.temp.cleanup)
  self.stack.enter_context(patch.object(hub,'APP_ROOT',self.root));self.stack.enter_context(patch.object(hub,'SOURCE_PINS',{self.rel:{'old':[hub.blob(self.old)],'new':hub.blob(self.new)}}))
  self.stack.enter_context(patch.object(hub.pwd,'getpwnam',return_value=self.user))
  def command(args,**kwargs):
   self.calls.append(args)
   return types.SimpleNamespace(returncode=0,stdout=self.new if 'show' in args else b'')
  self.stack.enter_context(patch.object(hub.subprocess,'run',side_effect=command))
 def install(self, verify=None):
  def probe(file,phase,root,php):
   if phase=='verify' and verify: return verify()
   return {'ok':True,'whatsapp_voice_ready':True,'meta_voice_ready':True}
  with patch.object(hub,'run_php',side_effect=probe),contextlib.redirect_stdout(io.StringIO()) as out:
   hub.install(self.root,'a'*40,'fixture-php')
  return out.getvalue()
 def test_publish_preserves_source_alias_and_unrelated_menu(self):
  alias=self.base/'alias';os.link(self.path,alias)
  output=self.install()
  self.assertEqual(self.path.read_bytes(),self.new);self.assertEqual(alias.read_bytes(),self.old)
  self.assertEqual(self.menu.read_text(),"native menu\n@include('admin.meta.sidebar')\nnative tail\n")
  self.assertIn('META_MESSAGE_HUB_READY',output)
  self.assertTrue(list((self.base/'meta-release-backups').glob('message-hub-*/manifest.json')))
 def test_runtime_failure_restores_every_published_file(self):
  def failed():raise RuntimeError('fixture verify failed')
  with self.assertRaisesRegex(RuntimeError,'fixture verify failed'):self.install(failed)
  self.assertEqual(self.path.read_bytes(),self.old);self.assertEqual(self.menu.read_bytes(),self.menuBefore)
 def test_unknown_source_stops_before_publication(self):
  self.path.write_bytes(b'operator modification')
  with self.assertRaisesRegex(RuntimeError,'BASELINE_CHANGED'):self.install()
  self.assertEqual(self.path.read_bytes(),b'operator modification');self.assertEqual(self.menu.read_bytes(),self.menuBefore)
 def test_concurrent_operator_edit_is_preserved_during_rollback(self):
  def changed():self.path.write_bytes(b'later operator edit');return {'ok':True}
  with self.assertRaisesRegex(RuntimeError,'CONCURRENT_EDIT'):self.install(changed)
  self.assertEqual(self.path.read_bytes(),b'later operator edit');self.assertEqual(self.menu.read_bytes(),self.menuBefore)
 def test_missing_meta_link_is_inserted_in_existing_whatsapp_position(self):
  self.assertEqual(hub.sidebar("before\n@include('admin.whatsapp.sidebar')\nafter\n"),"before\n                @include('admin.meta.sidebar')\nafter\n")
 def test_ambiguous_navigation_is_rejected(self):
  with self.assertRaisesRegex(RuntimeError,'NAVIGATION_LINK_AMBIGUOUS'):hub.navigation('custom navigation without reviewed anchor')
 def test_meta_moves_below_home_without_whatsapp_shortcut(self):
  self.assertEqual(hub.navigation("placeShortcut('whatsapp', 'dashboard');\nplaceShortcut('meta', 'whatsapp');\nother();"),"placeShortcut('meta', 'dashboard');\nother();")
if __name__=='__main__':unittest.main()
