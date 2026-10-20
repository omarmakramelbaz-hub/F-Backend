#!/usr/bin/env python3
"""Bounded source-bound tests; no network or external repository writes."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch


SPEC = importlib.util.spec_from_file_location('go_erp_live_source', Path(__file__).resolve().parents[1] / 'deployment/go_erp_live_source.py')
source = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(source)


@unittest.skipUnless(os.geteuid() == 0, 'root-owned isolated checkout contract')
class LiveSourceTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='go-erp-source-test-')
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.seed = self.base / 'seed'
        self.live = self.base / 'live'
        self.seed.mkdir()
        self.live.mkdir()
        self.uid = 1001
        self.git('init', '-q')
        self.git('remote', 'add', 'origin', source.REMOTE_URL)
        self.write(self.seed, '.gitattributes', b'* text=auto\n')
        self.write(self.seed, 'artisan', b'<?php // trusted bootstrap\n', 0o755)
        self.write(self.seed, 'app/Http/Kernel.php', b'<?php // kernel\n')
        self.write(self.seed, 'app/Example.php', b'<?php // old\n')
        self.write(self.seed, 'public/css/current.css', b'body { color: red; }\n')
        self.write(self.seed, 'public/dashboard/vendor/desktop-external/asset.bin', b'\x00trusted runtime\xff')
        self.write(self.seed, 'public/uploads/content/.gitignore', b'*\n!.gitignore\n')
        package = {'name': 'go-erp', 'version': '0.4.4-preview.6', 'private': True}
        lock = {'name': 'go-erp', 'version': package['version'], 'lockfileVersion': 3,
                'packages': {'': {'name': 'go-erp', 'version': package['version']}}}
        self.write(self.seed, 'desktop-pos/package.json', json.dumps(package).encode())
        self.write(self.seed, 'desktop-pos/package-lock.json', json.dumps(lock).encode())
        self.git('add', '.')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost', 'commit', '-qm', 'approved seed')
        self.revision = self.git('rev-parse', 'HEAD').decode().strip()
        self.write(self.live, 'artisan', b'<?php // live bootstrap\n', 0o755)
        self.write(self.live, 'app/Http/Kernel.php', b'<?php // current kernel\n')
        self.write(self.live, 'app/Example.php', b'<?php // live\n')
        self.write(self.live, 'public/css/current.css', b'body { color: blue; }\n')

    def git(self, *args):
        process = subprocess.run(['git', '-C', str(self.seed), *args], stdout=subprocess.PIPE,
                                 stderr=subprocess.PIPE, check=False, env={**os.environ, 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': os.devnull})
        if process.returncode:
            raise AssertionError('fixture Git failed: ' + process.stderr.decode())
        return process.stdout

    @staticmethod
    def write(root, name, data, mode=0o644):
        path = root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        path.chmod(mode)
        return path

    def scan(self, previous=None):
        return source.scan_snapshot(self.live, self.seed, self.uid, previous)

    def test_full_roster_live_new_code_and_retained_base_are_distinct(self):
        self.write(self.live, 'app/NewFeature.php', b'<?php // new\n')
        self.write(self.live, 'public/js/dashboard-new.js', b'const version = 2;\n')
        # The production root normally has .git and environment/vendor/storage.
        (self.live / '.git').mkdir()
        self.write(self.live, '.env', b'PRODUCTION_SECRET=do-not-read')
        self.write(self.live, 'vendor/ignored.php', b'<?php // private dependency')
        self.write(self.live, 'public/uploads/private.json', b'{"access_token":"secret"}')
        self.write(self.live, 'public/new-upload.png', b'\x89PNG\x00new media')
        snapshot = self.scan()
        meta = snapshot['metadata']
        self.assertEqual(meta['seedRevision'], self.revision)
        self.assertEqual(meta['inventory']['app/Example.php']['authority'], 'live')
        self.assertIn('app/NewFeature.php', meta['authoritativeLiveFiles'])
        self.assertIn('public/js/dashboard-new.js', meta['packagedFiles'])
        self.assertEqual(meta['inventory']['public/dashboard/vendor/desktop-external/asset.bin']['authority'], 'trusted-base')
        self.assertEqual(meta['inventory']['public/uploads/content/.gitignore']['authority'], 'trusted-base')
        self.assertNotIn('public/new-upload.png', meta['inventory'])
        self.assertFalse(any('private' in name or name == '.env' for name in meta['inventory']))
        self.assertEqual(snapshot['metadata'], self.scan()['metadata'])
        json.dumps(meta)  # private bytes are deliberately separate

    def test_live_checkout_head_is_not_source_roster(self):
        self.write(self.live, '.git/HEAD', b'not a valid Git repository')
        self.write(self.live, 'app/AddedOutsideProductionHEAD.php', b'<?php // hotfix\n')
        self.assertIn('app/AddedOutsideProductionHEAD.php', self.scan()['metadata']['authoritativeLiveFiles'])

    def test_tracked_public_binary_is_authoritative_when_live_present(self):
        self.write(self.live, 'public/dashboard/vendor/desktop-external/asset.bin', b'\x00changed live static\xff')
        snapshot = self.scan()
        self.assertEqual(snapshot['metadata']['inventory']['public/dashboard/vendor/desktop-external/asset.bin']['authority'], 'live')

    def test_actual_trusted_seed_asset_names_with_spaces_and_en_dash(self):
        # These are the three exact non-ASCII/space paths in the real seed.
        names = ['public/site/images/Cart – 1.png', 'public/site/images/Register – 2.png',
                 'public/site/images/Search – 2.png']
        for index, name in enumerate(names):
            self.write(self.seed, name, b'\x89PNG trusted ' + str(index).encode())
            self.write(self.live, name, b'\x89PNG live ' + str(index).encode())
        self.git('add', 'public/site')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost', 'commit', '-qm', 'actual trusted asset names')
        snapshot = source.scan_snapshot(self.live, self.seed, 0)
        for name in names:
            self.assertIn(name, snapshot['metadata']['authoritativeLiveFiles'])
            self.assertEqual(snapshot['_files'][name], (self.live / name).read_bytes())
        result = source.prepare_candidate(self.seed, snapshot, 7)
        for name in names:
            self.assertEqual(self.git('show', result['commit'] + ':' + name), snapshot['_files'][name])

    def test_windows_unsafe_names_and_new_unicode_code_fail_closed(self):
        for name in ['app/a:b.php', 'app/CON.php', 'app/prn', 'app/AUX.txt', 'app/COM1.txt',
                     'app/LPT9.php', 'app/trailing. ', 'app/end.', 'app//x.php', 'app/../x.php',
                     'app/.GIT/config', 'app/slash\\x.php', 'app/control\x7f.php',
                     'app/surrogate\ud800.php', 'app/astral\U0001f600.php']:
            with self.subTest(name=ascii(name)):
                self.assertFalse(source._path_ok(name))
        self.write(self.live, 'app/New – class.php', b'<?php // unclassified new path')
        with self.assertRaisesRegex(source.SourceError, 'UNCLASSIFIED_ADDITION'):
            self.scan()

    def test_actual_out_of_scope_dev_gitlink_is_not_dereferenced(self):
        self.git('update-index', '--add', '--cacheinfo',
                 '160000,f93135e3ef754847ef722044a01a381509d53a71,test.fasakhaninja.com/dev')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost', 'commit', '-qm', 'actual dev gitlink')
        snapshot = self.scan()
        self.assertFalse(any('test.fasakhaninja.com' in name for name in snapshot['metadata']['inventory']))
        result = source.prepare_candidate(self.seed, snapshot, 7)
        self.assertEqual(len(result['commit']), 40)

    def test_in_scope_submodule_is_not_an_approved_runtime_file(self):
        self.git('update-index', '--add', '--cacheinfo',
                 '160000,f93135e3ef754847ef722044a01a381509d53a71,public/linked-code')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@localhost', 'commit', '-qm', 'unsafe runtime gitlink')
        with self.assertRaisesRegex(source.SourceError, 'GIT_SOURCE_SUBMODULE'):
            self.scan()

    def test_live_only_removal_does_not_fall_back_to_seed(self):
        first = self.scan()
        (self.live / 'app/Example.php').unlink()
        second = self.scan(first['metadata'])
        row = second['metadata']['inventory']['app/Example.php']
        self.assertEqual(row, {'authority': 'removed', 'present': False, 'sha256': None, 'bytes': 0, 'mode': '100644'})
        self.assertNotIn('app/Example.php', second['_files'])
        third = self.scan(second['metadata'])
        self.assertEqual(second['metadata'], third['metadata'])
        self.assertNotEqual(first['metadata']['inventorySha256'], second['metadata']['inventorySha256'])

    def test_new_live_file_removal_is_in_inventory(self):
        path = self.write(self.live, 'routes/new.php', b'<?php // route\n')
        first = self.scan()
        path.unlink()
        self.assertIn('routes/new.php', self.scan(first['metadata'])['metadata']['removedFiles'])

    def test_critical_removal_blocks_snapshot(self):
        first = self.scan()
        (self.live / 'artisan').unlink()
        with self.assertRaisesRegex(source.SourceError, 'CRITICAL_REMOVAL'):
            self.scan(first['metadata'])

    def test_symlink_file_directory_root_and_hardlinks_fail_closed(self):
        outside = self.write(self.base, 'outside.php', b'<?php secret')
        (self.live / 'app/escape.php').symlink_to(outside)
        with self.assertRaises(source.SourceError):
            self.scan()
        (self.live / 'app/escape.php').unlink()
        (self.live / 'routes').symlink_to(self.base, target_is_directory=True)
        with self.assertRaises(source.SourceError):
            self.scan()
        (self.live / 'routes').unlink()
        linked = self.base / 'linked-live'
        linked.symlink_to(self.live, target_is_directory=True)
        with self.assertRaises(source.SourceError):
            source.scan_snapshot(linked, self.seed, self.uid)
        os.link(self.live / 'app/Example.php', self.live / 'app/Alias.php')
        with self.assertRaisesRegex(source.SourceError, 'LIVE_FILE_TYPE'):
            self.scan()

    def test_excluded_storage_symlink_is_never_followed(self):
        (self.live / 'public/storage').symlink_to(self.base, target_is_directory=True)
        self.assertNotIn('public/storage', self.scan()['metadata']['inventory'])

    def test_unclassified_code_root_addition_and_credentials_fail_closed(self):
        path = self.write(self.live, 'config/unknown.txt', b'private configuration')
        with self.assertRaisesRegex(source.SourceError, 'UNCLASSIFIED_ADDITION'):
            self.scan()
        path.unlink()
        path = self.write(self.live, 'config/new.json', b'{"nested":[{"access_token":"never copy"}]}')
        with self.assertRaisesRegex(source.SourceError, 'CREDENTIAL_JSON'):
            self.scan()
        path.unlink()
        self.write(self.live, 'config/firebase.json.backup', b'private')
        self.assertFalse(any('firebase' in name for name in self.scan()['metadata']['inventory']))

    def test_deep_json_cannot_bypass_credential_classification(self):
        self.write(self.live, 'config/deep.json', b'['*1100 + b'{"private_key":"never copy"}' + b']'*1100)
        with self.assertRaisesRegex(source.SourceError, 'JSON_DEPTH'):
            self.scan()

    def test_source_namespace_named_storage_is_not_treated_as_uploads(self):
        self.write(self.live, 'app/Storage/Handler.php', b'<?php // namespace code')
        self.assertIn('app/Storage/Handler.php', self.scan()['metadata']['authoritativeLiveFiles'])

    def test_mutation_between_passes_blocks_even_if_names_match(self):
        original = source._live_pass
        calls = 0
        def changing(*args):
            nonlocal calls
            result = original(*args)
            calls += 1
            if calls == 1:
                self.write(self.live, 'app/Example.php', b'<?php // changed during capture\n')
            return result
        with patch.object(source, '_live_pass', side_effect=changing):
            with self.assertRaisesRegex(source.SourceError, 'SNAPSHOT_CHANGED'):
                self.scan()

    def test_candidate_raw_crlf_versions_inventory_and_push_retry(self):
        self.write(self.live, 'app/Example.php', b'<?php\r\n// exact live CRLF\r\n')
        first = self.scan()
        result = source.prepare_candidate(self.seed, first, 9)
        self.assertEqual(result['version'], '0.4.4-preview.9')
        self.assertFalse(result['reused'])
        committed = self.git('show', result['commit'] + ':app/Example.php')
        self.assertEqual(committed, first['_files']['app/Example.php'])
        candidate = json.loads(self.git('show', result['commit'] + ':' + source.CANDIDATE_PATH))
        self.assertEqual(candidate['inventorySha256'], first['metadata']['inventorySha256'])
        self.assertEqual(candidate['authoritativeLiveFiles'], first['metadata']['authoritativeLiveFiles'])
        package = json.loads(self.git('show', result['commit'] + ':desktop-pos/package.json'))
        lock = json.loads(self.git('show', result['commit'] + ':desktop-pos/package-lock.json'))
        self.assertEqual((package['version'], lock['version'], lock['packages']['']['version']), (result['version'],)*3)
        repeated = source.prepare_candidate(self.seed, first, 9)
        self.assertTrue(repeated['reused'])
        self.assertEqual(result['commit'], repeated['commit'])
        # Reconstruct the candidate with the same checkout EOL policy required
        # of Windows CI; source authority must survive an actual Git checkout.
        reconstructed = self.base / 'reconstructed'
        process = subprocess.run(['git', '-c', 'core.autocrlf=false', '-c', 'core.eol=lf',
            'clone', '--quiet', '--no-local', '--no-hardlinks', str(self.seed), str(reconstructed)],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            env={**os.environ, 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': os.devnull}, check=False)
        self.assertEqual(process.returncode, 0)
        self.assertEqual((reconstructed / 'app/Example.php').read_bytes(), committed)

    def test_candidate_removes_previously_live_file(self):
        first = self.scan()
        (self.live / 'app/Example.php').unlink()
        second = self.scan(first['metadata'])
        result = source.prepare_candidate(self.seed, second, 7)
        self.assertFalse((self.seed / 'app/Example.php').exists())
        self.assertNotIn('app/Example.php', self.git('ls-tree', '-r', '--name-only', result['commit']).decode().splitlines())

    def test_version_sequence_strictly_advances_and_snapshot_tampering_rejected(self):
        snapshot = self.scan()
        for invalid in (0, -1, True, 6, '9', source.MAX_SEQUENCE+1):
            with self.subTest(invalid=invalid), self.assertRaises(source.SourceError):
                source.prepare_candidate(self.seed, snapshot, invalid)
        corrupted = copy.deepcopy(snapshot)
        corrupted['_files']['app/Example.php'] = b'changed after capture'
        with self.assertRaisesRegex(source.SourceError, 'SNAPSHOT_BYTES'):
            source.prepare_candidate(self.seed, corrupted, 7)
        self.assertEqual(self.git('rev-parse', 'HEAD').decode().strip(), self.revision)

    def test_checkout_dirty_or_hostile_git_config_blocks_before_source_copy(self):
        snapshot = self.scan()
        self.git('config', 'filter.bad.clean', 'echo do-not-run')
        with self.assertRaisesRegex(source.SourceError, 'GIT_CONFIG'):
            source.prepare_candidate(self.seed, snapshot, 7)
        self.git('config', '--remove-section', 'filter.bad')
        self.write(self.seed, 'app/Example.php', b'dirty root checkout')
        with self.assertRaisesRegex(source.SourceError, 'GIT_DIRTY'):
            source.prepare_candidate(self.seed, snapshot, 7)

    def test_root_owned_hooks_are_disabled_for_candidate(self):
        marker = self.base / 'hook-ran'
        hook = self.write(self.seed, '.git/hooks/post-commit', ('#!/bin/sh\ntouch ' + str(marker) + '\n').encode(), 0o755)
        result = source.prepare_candidate(self.seed, self.scan(), 7)
        self.assertFalse(marker.exists())
        self.assertEqual(len(result['commit']), 40)

    def test_web_user_source_owner_is_allowed_other_owner_and_world_write_rejected(self):
        path = self.live / 'app/Example.php'
        try:
            os.chown(path, self.uid, self.uid)
        except OSError as error:
            # Root inside an executor user namespace may have only UID 0
            # mapped. The world-write rejection is still exercised below.
            if error.errno not in (1, 22):
                raise
        else:
            self.assertIn('app/Example.php', self.scan()['metadata']['authoritativeLiveFiles'])
            os.chown(path, self.uid+1, self.uid+1)
            with self.assertRaisesRegex(source.SourceError, 'FILE_OWNER'):
                self.scan()
            os.chown(path, 0, 0)
        path.chmod(0o666)
        with self.assertRaisesRegex(source.SourceError, 'WORLD_WRITABLE'):
            self.scan()

    def test_replacement_refs_and_non_root_git_config_are_rejected(self):
        replace = self.seed / '.git/refs/replace'
        replace.mkdir()
        with self.assertRaisesRegex(source.SourceError, 'GIT_EXTERNAL_OBJECTS'):
            self.scan()
        replace.rmdir()
        (self.seed / '.git/config').chmod(0o666)
        with self.assertRaisesRegex(source.SourceError, 'GIT_TRUST'):
            self.scan()

    def test_heartbeat_failure_stops_before_candidate_mutation(self):
        snapshot = self.scan()
        def stop():
            raise source.SourceError('source no longer live')
        with self.assertRaisesRegex(source.SourceError, 'no longer live'):
            source.prepare_candidate(self.seed, snapshot, 7, heartbeat=stop)
        self.assertEqual(self.git('rev-parse', 'HEAD').decode().strip(), self.revision)
        self.assertFalse((self.seed / source.CANDIDATE_PATH).exists())

    def test_inventory_tampering_and_forged_retained_bytes_fail_closed(self):
        snapshot = self.scan()
        bad = copy.deepcopy(snapshot)
        bad['metadata']['inventory']['app/Example.php']['mode'] = '100777'
        with self.assertRaises(source.SourceError):
            source.prepare_candidate(self.seed, bad, 7)
        bad = copy.deepcopy(snapshot)
        path = 'public/dashboard/vendor/desktop-external/asset.bin'
        bad['_files'][path] = b'forged trusted bytes'
        bad['metadata']['inventory'][path]['sha256'] = source._digest(bad['_files'][path])
        bad['metadata']['inventory'][path]['bytes'] = len(bad['_files'][path])
        bad['metadata'] = source._metadata(self.revision, bad['metadata']['inventory'])
        with self.assertRaisesRegex(source.SourceError, 'RETAINED_BASE_MISMATCH'):
            source.prepare_candidate(self.seed, bad, 7)


if __name__ == '__main__':
    unittest.main()
