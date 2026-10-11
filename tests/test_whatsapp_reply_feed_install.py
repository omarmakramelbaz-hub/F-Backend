#!/usr/bin/env python3
"""Guarded accepted-reply feed publication and preservation regressions."""
import importlib.util
import json
import os
import pathlib
import sys
import tempfile
import unittest
from unittest.mock import patch

DEPLOYMENT = pathlib.Path(__file__).absolute().parent.parent / 'deployment'
spec = importlib.util.spec_from_file_location('reply_feed_install', DEPLOYMENT / 'apply_whatsapp_reply_feed.py')
fix = importlib.util.module_from_spec(spec); sys.modules[spec.name] = fix; spec.loader.exec_module(fix)


class ProposalTest(unittest.TestCase):
    def test_scoped_additive_view_patch_and_idempotency(self):
        pairs = [{'old': 'old Arabic label', 'new': 'new Arabic label'},
                 {'old': 'old English label', 'new': 'new English label'}]
        original = b'custom live prefix\nold Arabic label\nold English label\ncustom live suffix'
        after = fix.view_proposal(original, pairs)
        self.assertEqual(after, b'custom live prefix\nnew Arabic label\nnew English label\ncustom live suffix')
        self.assertEqual(fix.view_proposal(after, pairs), after)

    def test_ambiguous_view_patch_refuses_to_guess(self):
        pair = [{'old': 'old', 'new': 'new'}]
        for value in (b'missing', b'old old', b'old new', b'new new'):
            with self.assertRaises(fix.SafeError):
                fix.view_proposal(value, pair)

    def test_production_multiline_label_keeps_crlf_and_is_idempotent(self):
        pair = [{
            'old': "'accepted'=>'Sending was accepted. A copy will appear when the WhatsApp webhook arrives.',",
            'new': "'accepted'=>'WhatsApp accepted the send; your reply appears in the conversation.',\n    'api_source'=>'Dashboard reply · accepted by WhatsApp',"
        }]
        original = (b'live-prefix\r\n    ' + pair[0]['old'].encode() + b'\r\nlive-suffix\r\n')
        after = fix.view_proposal(original, pair)
        self.assertNotIn(b'\n', after.replace(b'\r\n', b''))
        self.assertIn(b"\r\n    'api_source'", after)
        self.assertEqual(fix.view_proposal(after, pair), after)
        self.assertEqual(after.replace(pair[0]['new'].encode().replace(b'\n', b'\r\n'),
                                       pair[0]['old'].encode()), original)
        with self.assertRaisesRegex(fix.SafeError, 'MIXED_NEWLINES'):
            fix.view_proposal(original + b'mixed\n', pair)


class InstallTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        parent = pathlib.Path(self.tmp.name)
        self.root = parent / 'public_html'; self.root.mkdir(); (self.root / '.git').mkdir()
        self.payload = parent / 'payload'; (self.payload / 'deployment').mkdir(parents=True)
        self.uid = os.geteuid(); self.originals = {}; self.sources = {}; pins = {}
        for index, relative in enumerate(fix.SOURCE_PINS):
            old, new = ('old-source-' + str(index)).encode(), ('new-source-' + str(index)).encode()
            self.write(self.root / relative, old); self.write(self.payload / relative, new)
            self.originals[relative] = old; self.sources[relative] = new
            pins[relative] = (fix.base.blob_hash(old), fix.base.blob_hash(new))
        patcher = patch.dict(fix.SOURCE_PINS, pins, clear=True); patcher.start(); self.addCleanup(patcher.stop)
        views = []
        for index, (relative, name) in enumerate(fix.VIEW_NAMES.items()):
            old, new = 'old-label-' + str(index), 'new-label-' + str(index)
            self.originals[relative] = ('live-prefix ' + old + ' live-suffix').encode()
            self.write(self.root / relative, self.originals[relative])
            views.append({'path': relative, 'name': name, 'replacements': [{'old': old, 'new': new}]})
        self.originals[fix.spinner.FOOTER_PATH] = b'live footer prefix\n' + fix.spinner.HANDLER + b'\nlive footer suffix'
        self.write(self.root / fix.spinner.FOOTER_PATH, self.originals[fix.spinner.FOOTER_PATH])
        self.data = {'version': 1, 'sources': [
            {'path': relative, 'before_blob': values[0], 'after_blob': values[1]}
            for relative, values in fix.SOURCE_PINS.items()], 'views': views}
        self.manifest = self.payload / 'deployment/whatsapp_reply_feed_manifest.json'
        self.rewrite_manifest()
        self.compiled = []

    def write(self, path, content):
        path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(content); path.chmod(0o644)

    def rewrite_manifest(self):
        self.write(self.manifest, json.dumps(self.data).encode())
        self.digest = fix.base.blob_hash(self.manifest.read_bytes())

    def runner(self, root, args, capture=False, **kwargs):
        if len(args) > 2 and args[1:3] == ['-r', fix.COMPILE_PHP]:
            self.compiled.append(args[5]); pathlib.Path(args[4]).write_bytes(b'<?php // compiled candidate')
            return 0, b'{"compiled_mtime":0}'
        return 0, b''

    def run_fix(self, runner=None):
        return fix.apply(self.root, self.uid, self.manifest, self.digest, runner=runner or self.runner)

    def test_all_sources_and_three_live_views_publish_with_backup(self):
        status, backup = self.run_fix()
        self.assertEqual(status, 'WHATSAPP_ACCEPTED_REPLY_FEED_READY')
        self.assertEqual(set(self.compiled), {'admin.whatsapp.index', 'admin.whatsapp.replies', 'admin.layouts.footer'})
        for relative, expected in self.sources.items():
            self.assertEqual((self.root / relative).read_bytes(), expected)
        for relative in fix.VIEW_NAMES:
            self.assertIn(b'live-prefix', (self.root / relative).read_bytes())
            self.assertIn(b'live-suffix', (self.root / relative).read_bytes())
        self.assertEqual((self.root / fix.spinner.FOOTER_PATH).read_bytes().replace(
            fix.spinner.NEW_SELECTOR, fix.spinner.OLD_SELECTOR), self.originals[fix.spinner.FOOTER_PATH])
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        self.assertEqual(json.loads((backup / 'receipt.json').read_text())['files'].keys(), self.originals.keys())
        self.run_fix()  # An already-published source is accepted only at its exact new hash.

    def test_unknown_live_source_refuses_before_any_write(self):
        relative = next(iter(fix.SOURCE_PINS)); self.write(self.root / relative, b'peer live edit')
        with self.assertRaisesRegex(fix.SafeError, 'LIVE_HASH_NEEDS_REVIEW'):
            self.run_fix()
        self.assertEqual((self.root / relative).read_bytes(), b'peer live edit')
        for other, original in self.originals.items():
            if other != relative:
                self.assertEqual((self.root / other).read_bytes(), original)

    def test_manifest_cannot_extend_scope_or_ignore_payload_hash(self):
        self.data['sources'].append({'path': '.env', 'before_blob': 'a' * 40, 'after_blob': 'b' * 40})
        self.rewrite_manifest()
        with self.assertRaisesRegex(fix.SafeError, 'MANIFEST_SCOPE_INVALID'):
            self.run_fix()
        self.data['sources'].pop(); self.rewrite_manifest()
        relative = next(iter(fix.SOURCE_PINS)); self.write(self.payload / relative, b'changed payload')
        with self.assertRaisesRegex(fix.SafeError, 'PAYLOAD_HASH_MISMATCH'):
            self.run_fix()

    def test_template_compile_failure_preserves_every_live_file(self):
        def runner(root, args, **kwargs):
            if len(args) > 2 and args[1:3] == ['-r', fix.COMPILE_PHP]:
                return 1, b''
            return self.runner(root, args, **kwargs)
        with self.assertRaisesRegex(fix.SafeError, 'TEMPLATE_COMPILE_FAILED'):
            self.run_fix(runner)
        for relative, original in self.originals.items():
            self.assertEqual((self.root / relative).read_bytes(), original)

    def test_failed_read_only_smoke_restores_all_six_files(self):
        def runner(root, args, **kwargs):
            if len(args) > 2 and args[1:3] == ['-r', fix.SMOKE_PHP]:
                return 1, b''
            return self.runner(root, args, **kwargs)
        with self.assertRaisesRegex(fix.SafeError, 'REPLY_FEED_FILES_RESTORED'):
            self.run_fix(runner)
        for relative, original in self.originals.items():
            self.assertEqual((self.root / relative).read_bytes(), original)

    def test_failed_smoke_retains_competing_edit_and_restores_others(self):
        relative = next(iter(fix.SOURCE_PINS)); competing = b'peer publication kept'
        def runner(root, args, **kwargs):
            if len(args) > 2 and args[1:3] == ['-r', fix.SMOKE_PHP]:
                self.write(self.root / relative, competing); return 1, b''
            return self.runner(root, args, **kwargs)
        with self.assertRaisesRegex(fix.SafeError, 'ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED'):
            self.run_fix(runner)
        self.assertEqual((self.root / relative).read_bytes(), competing)
        for other, original in self.originals.items():
            if other != relative:
                self.assertEqual((self.root / other).read_bytes(), original)


if __name__ == '__main__':
    unittest.main()
