#!/usr/bin/env python3
"""Regression coverage for the AJAX-only spinner exclusion and recovery."""
import importlib.util
import json
import os
import pathlib
import shutil
import subprocess
import sys
import tempfile
import unittest

DEPLOYMENT = pathlib.Path(__file__).absolute().parent.parent / 'deployment'
spec = importlib.util.spec_from_file_location('whatsapp_reply_spinner_fix', DEPLOYMENT / 'apply_whatsapp_reply_spinner_fix.py')
fix = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = fix
spec.loader.exec_module(fix)


class SpinnerFixTest(unittest.TestCase):
    def test_exact_live_patch_keeps_other_footer_changes_and_crlf(self):
        for newline in (b'\n', b'\r\n'):
            before = b'LIVE_CUSTOM_PREFIX\n' + fix.HANDLER.replace(b'\n', newline) + b'\nLIVE_CUSTOM_SUFFIX'
            after = fix.proposal(before)
            self.assertEqual(after.replace(fix.NEW_SELECTOR, fix.OLD_SELECTOR), before)
            self.assertEqual(fix.proposal(after), after)

    def test_ambiguous_or_modified_handler_stops(self):
        for before in (b'', fix.HANDLER * 2,
                       fix.HANDLER + fix.HANDLER.replace(fix.OLD_SELECTOR, fix.NEW_SELECTOR),
                       fix.HANDLER.replace(b'fa fa-spinner', b'custom-spinner')):
            with self.assertRaises(fix.SafeError):
                fix.proposal(before)

    def test_reply_form_keeps_its_label_while_regular_form_gets_spinner(self):
        node = shutil.which('node')
        if not node:
            self.fail('Node is required for the submit-handler behavioral regression test')
        handler = fix.proposal(fix.HANDLER).decode()
        js = r'''
const vm = require('vm');
const forms = [
  { attributes: {'data-wa-reply-form': ''}, button: {html: 'Send WhatsApp reply'}, calls: 0 },
  { attributes: {}, button: {html: 'Save normal form'}, calls: 0 }
];
function wrap(items) {
 return {
   not(selector) {
     if (selector !== '[data-wa-reply-form]') throw Error('Unexpected exclusion');
     return wrap(items.filter(form => !Object.hasOwn(form.attributes, 'data-wa-reply-form')));
   },
   submit(handler) {items.forEach(form => {form.handler = handler});},
   find(selector) {
     if (selector !== "button[type='submit']") throw Error('Wrong button');
     return {html(value) {items.forEach(form => {form.button.html = value; form.calls++})}};
   }
 };
}
function $(value) { return wrap(value === 'form' ? forms : [value]); }
vm.runInNewContext(HANDLER, {$});
forms.forEach(form => {if (form.handler) form.handler.call(form);});
if (forms[0].button.html !== 'Send WhatsApp reply' || forms[0].calls !== 0)
 throw Error('WhatsApp reply label was changed');
if (forms[1].calls !== 1 || !forms[1].button.html.includes('fa-spinner'))
 throw Error('Normal-form spinner behavior changed');
'''
        # Resolve only the already-existing Blade translation inside this JS test.
        handler = handler.replace("@lang('main.under click')", 'Submitting')
        code = 'const HANDLER = ' + json.dumps(handler) + ';\n' + js
        result = subprocess.run([node, '-e', code], capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)


class PublicationTest(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        self.root = pathlib.Path(self.folder.name) / 'public_html'
        self.root.mkdir(); (self.root / '.git').mkdir()
        self.footer = self.root / fix.FOOTER_PATH
        self.footer.parent.mkdir(parents=True)
        self.before = b'preserve-live-settings\n' + fix.HANDLER + b'\nend-live-settings'
        self.footer.write_bytes(self.before); self.footer.chmod(0o644)
        self.uid = os.geteuid()

    def runner(self, root, args, capture=False, **kwargs):
        if len(args) > 2 and args[1:3] == ['-r', fix.COMPILE_PHP]:
            pathlib.Path(args[4]).write_bytes(b'<?php echo "candidate";')
            return 0, b'{"compiled_mtime":0}'
        return 0, b''

    def test_atomic_publication_backup_and_idempotent_second_run(self):
        status, backup = fix.apply(self.root, self.uid, runner=self.runner)
        self.assertEqual(status, 'WHATSAPP_REPLY_SPINNER_FIXED')
        self.assertEqual(self.footer.read_bytes(), fix.proposal(self.before))
        self.assertEqual((backup / 'footer.before.blade.php').read_bytes(), self.before)
        self.assertEqual(self.footer.stat().st_mode & 0o777, 0o644)
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        self.assertEqual(fix.apply(self.root, self.uid, runner=self.runner),
                         ('WHATSAPP_REPLY_SPINNER_ALREADY_FIXED', None))

    def test_failed_smoke_restores_original_atomically(self):
        def runner(root, args, **kwargs):
            if len(args) > 2 and args[1:3] == ['-r', fix.SMOKE_PHP]:
                return 1, b''
            return self.runner(root, args, **kwargs)
        with self.assertRaisesRegex(fix.SafeError, 'WHATSAPP_SPINNER_SOURCE_RESTORED'):
            fix.apply(self.root, self.uid, runner=runner)
        self.assertEqual(self.footer.read_bytes(), self.before)

    def test_concurrent_edit_is_kept_when_smoke_fails(self):
        competing = b'peer edit kept\n' + fix.HANDLER
        def runner(root, args, **kwargs):
            if len(args) > 2 and args[1:3] == ['-r', fix.SMOKE_PHP]:
                self.footer.write_bytes(competing)
                return 1, b''
            return self.runner(root, args, **kwargs)
        with self.assertRaisesRegex(fix.SafeError, 'ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):
            fix.apply(self.root, self.uid, runner=runner)
        self.assertEqual(self.footer.read_bytes(), competing)

    def test_compile_failure_leaves_live_footer_unchanged(self):
        with self.assertRaisesRegex(fix.SafeError, 'TEMPLATE_COMPILE_FAILED'):
            fix.apply(self.root, self.uid, runner=lambda *args, **kwargs: (1, b''))
        self.assertEqual(self.footer.read_bytes(), self.before)


if __name__ == '__main__':
    unittest.main()
