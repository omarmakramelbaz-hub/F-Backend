"""Real temporary Git trees and live folders exercise the desktop sync API installer."""
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import pwd
import stat
import subprocess
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location(
    'desktop_sync_install', Path(__file__).resolve().parents[1] / 'deployment/install_go_erp_desktop_sync_root.py')
operator = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(operator)

PROVIDER = """<?php
class RouteServiceProvider
{
    public function boot()
    {
        $this->routes(function () {
            Route::middleware('api')->prefix('api')->group(base_path('routes/go_services.php'));
            Route::middleware('web')->group(base_path('routes/web.php'));
        });
    }
}
"""


class DesktopSyncInstallTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-desktop-sync-')
        base = Path(self.temporary.name)
        self.package, self.root, self.backups, self.lock = base / 'package', base / 'live', base / 'backups', base / 'watcher.lock'
        self.package.mkdir()
        self.lock.write_text('')
        # A stand-in PHP: lint always passes, artisan calls are recorded and can be made to fail.
        self.php = base / 'php'
        self.calls = base / 'artisan-calls.log'
        self.artisan_exit = base / 'artisan-exit'
        self.php.write_text('#!/bin/sh\nif [ "$1" = "artisan" ]; then shift; echo "$@" >> "%s"; exit $(cat "%s" 2>/dev/null || echo 0); fi\nexit 0\n'
                            % (self.calls, self.artisan_exit))
        self.php.chmod(0o755)
        self.git('init', '--quiet')
        self.git('config', 'user.email', 'test@example.invalid')
        self.git('config', 'user.name', 'Test')
        self.write(self.package / operator.PROVIDER, PROVIDER)
        self.write(self.package / 'routes/web.php', '<?php // unchanged\n')
        self.write(self.package / 'app/Services/Dashboard/OperatingDay.php', '<?php // existing\n')
        self.base = self.commit('live site')
        for name in operator.NEW_FILES:
            self.write(self.package / name, '<?php // new ' + name + '\n')
        self.write(self.package / operator.PROVIDER, operator.add_route_line(PROVIDER.encode()).decode())
        self.write(self.package / 'go-erp-desktop/src/main/main.cjs', '// the desktop app itself is not server source\n')
        self.write(self.package / 'tests/Feature/DesktopSyncTest.php', '<?php // test\n')
        self.release = self.commit('desktop sync api')
        self.original_base = operator.BASE
        operator.BASE = self.base
        self.write(self.root / operator.PROVIDER, PROVIDER)
        os.chmod(self.root / operator.PROVIDER, 0o640)
        for folder in ('app/Http/Controllers/Api', 'app/Services/Dashboard', 'database/migrations', 'routes'):
            (self.root / folder).mkdir(parents=True, exist_ok=True)
        self.uid = os.getuid()
        self.status = 401

    def tearDown(self):
        operator.BASE = self.original_base
        self.temporary.cleanup()

    def git(self, *arguments):
        return subprocess.run(['git', '-C', str(self.package)] + list(arguments), check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout.decode().strip()

    def write(self, path, text):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)

    def commit(self, message):
        self.git('add', '-A')
        self.git('commit', '--quiet', '-m', message)
        return self.git('rev-parse', 'HEAD')

    def run_operator(self, mode, backup=None):
        return operator.run(self.root, self.package, self.release, self.uid, str(self.php), mode, backup=backup,
                            backups=self.backups, watcher_lock=self.lock, web_user=pwd.getpwuid(os.getuid()).pw_name,
                            check=lambda: self.status)

    def live(self, name):
        path = self.root / name
        return path.read_text() if path.exists() else None

    def artisan_calls(self):
        return self.calls.read_text().splitlines() if self.calls.exists() else []

    def test_plan_reads_only(self):
        result = self.run_operator('plan')
        self.assertEqual(result['result'], 'GO_ERP_DESKTOP_SYNC_PLAN')
        self.assertEqual(result['route_provider'], 'replaced')
        self.assertTrue(result['new_folder'])
        self.assertTrue(all(self.live(name) is None for name in operator.NEW_FILES))
        self.assertEqual(self.live(operator.PROVIDER), PROVIDER)
        self.assertFalse(self.backups.exists())
        self.assertEqual(self.artisan_calls(), [])

    def test_apply_adds_the_files_runs_one_migration_and_rollback_restores(self):
        result = self.run_operator('apply')
        self.assertEqual(result['result'], 'GO_ERP_DESKTOP_SYNC_INSTALLED')
        self.assertEqual(result['live_check'], 'READY')
        for name in operator.NEW_FILES:
            self.assertEqual(self.live(name), '<?php // new ' + name + '\n')
            self.assertEqual(stat.S_IMODE((self.root / name).stat().st_mode), 0o644)
        self.assertEqual(stat.S_IMODE((self.root / operator.NEW_DIRECTORY).stat().st_mode), 0o755)
        provider = self.live(operator.PROVIDER)
        self.assertEqual(provider.count("routes/desktop_sync.php"), 1)
        self.assertLess(provider.index('go_services.php'), provider.index('desktop_sync.php'))
        self.assertLess(provider.index('desktop_sync.php'), provider.index('routes/web.php'))
        self.assertEqual(stat.S_IMODE((self.root / operator.PROVIDER).stat().st_mode), 0o640, 'The provider keeps its permissions')
        self.assertEqual(self.artisan_calls(), ['migrate --path=' + operator.MIGRATION + ' --force'])
        self.assertIsNone(self.live('routes/web.php'), 'Nothing else is written')
        self.assertEqual(self.run_operator('plan')['result'], 'GO_ERP_DESKTOP_SYNC_FILES_ALREADY_INSTALLED')
        backup = Path(result['backup'])
        self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)
        rolled = self.run_operator('rollback', backup=backup)
        self.assertEqual(rolled['result'], 'GO_ERP_DESKTOP_SYNC_ROLLED_BACK')
        self.assertTrue(all(self.live(name) is None for name in operator.NEW_FILES))
        self.assertFalse((self.root / operator.NEW_DIRECTORY).exists())
        self.assertEqual(self.live(operator.PROVIDER), PROVIDER)
        self.assertEqual(self.run_operator('plan')['result'], 'GO_ERP_DESKTOP_SYNC_PLAN')

    def test_running_apply_again_only_rechecks(self):
        self.run_operator('apply')
        again = self.run_operator('apply')
        self.assertEqual(again['result'], 'GO_ERP_DESKTOP_SYNC_ALREADY_INSTALLED')
        self.assertEqual(len(self.artisan_calls()), 2, 'The migration is safe to repeat')

    def test_a_slightly_different_live_provider_gets_only_the_new_line(self):
        edited = PROVIDER.replace('class RouteServiceProvider', 'class RouteServiceProvider // live comment')
        self.write(self.root / operator.PROVIDER, edited)
        result = self.run_operator('apply')
        self.assertEqual(result['route_provider'], 'line-added')
        self.assertEqual(self.live(operator.PROVIDER), operator.add_route_line(edited.encode()).decode())
        self.assertEqual(self.run_operator('plan')['result'], 'GO_ERP_DESKTOP_SYNC_FILES_ALREADY_INSTALLED')

    def test_an_unknown_live_provider_stops_before_any_write(self):
        self.write(self.root / operator.PROVIDER, '<?php // rewritten by hand\n')
        with self.assertRaisesRegex(operator.Stop, 'ROUTE_PROVIDER_DIFFERS'):
            self.run_operator('apply')
        self.assertTrue(all(self.live(name) is None for name in operator.NEW_FILES))

    def test_a_failed_migration_restores_every_file(self):
        self.artisan_exit.write_text('1')
        with self.assertRaisesRegex(operator.Stop, 'MIGRATION_FAILED FILES_RESTORED'):
            self.run_operator('apply')
        self.assertTrue(all(self.live(name) is None for name in operator.NEW_FILES))
        self.assertFalse((self.root / operator.NEW_DIRECTORY).exists())
        self.assertEqual(self.live(operator.PROVIDER), PROVIDER)

    def test_a_failed_write_restores_every_file(self):
        original = operator.live_write
        calls = []

        def failing(root, name, data, uid, gid, mode):
            calls.append(name)
            if len(calls) == 4:
                raise OSError('disk full')
            return original(root, name, data, uid, gid, mode)
        operator.live_write = failing
        try:
            with self.assertRaisesRegex(operator.Stop, 'APPLY_FAILED RESTORED'):
                self.run_operator('apply')
        finally:
            operator.live_write = original
        self.assertTrue(all(self.live(name) is None for name in operator.NEW_FILES))
        self.assertEqual(self.live(operator.PROVIDER), PROVIDER)
        self.assertEqual(self.artisan_calls(), [], 'No migration after a failed write')

    def test_live_check_results_are_reported_without_failing(self):
        self.status = 404
        result = self.run_operator('apply')
        self.assertEqual(result['live_check'], 'ROUTE_NOT_VISIBLE_YET_RELOAD_PHP')
        self.assertEqual(self.live(operator.MIGRATION), '<?php // new ' + operator.MIGRATION + '\n')

    def test_a_cached_route_list_is_cleared(self):
        self.write(self.root / operator.ROUTE_CACHE, '<?php return [];\n')
        result = self.run_operator('apply')
        self.assertTrue(result['route_cache_cleared'])
        self.assertEqual(self.artisan_calls()[-1], 'route:clear')

    def test_existing_new_files_on_the_server_are_never_overwritten(self):
        self.write(self.root / 'routes/desktop_sync.php', '<?php // someone else\n')
        with self.assertRaisesRegex(operator.Stop, 'NEW_FILES_DIFFER_FROM_EVERY_RELEASE'):
            self.run_operator('apply')
        self.assertEqual(self.live('routes/desktop_sync.php'), '<?php // someone else\n')

    def test_an_unreviewed_source_change_in_the_package_is_refused(self):
        self.write(self.package / 'routes/web.php', '<?php // unexpected\n')
        self.release = self.commit('extra change')
        with self.assertRaisesRegex(operator.Stop, 'UNREVIEWED_SOURCE_DELTA'):
            self.run_operator('plan')

    def test_a_busy_update_worker_is_never_raced(self):
        held = os.open(str(self.lock), os.O_RDWR)
        try:
            fcntl.flock(held, fcntl.LOCK_EX)
            with self.assertRaisesRegex(operator.Stop, 'UPDATE_WORKER_BUSY'):
                self.run_operator('apply')
        finally:
            os.close(held)

    def test_an_earlier_release_on_the_server_is_upgraded_and_rollback_restores_it(self):
        self.run_operator('apply')
        changed = 'app/Services/DesktopSync/DesktopSyncOperations.php'
        self.write(self.package / changed, '<?php // new version with expenses\n')
        self.release = self.commit('expenses and cash drawer')
        plan = self.run_operator('plan')
        self.assertEqual(plan['mode'], 'upgrade')
        self.assertEqual(plan['route_provider'], 'kept')
        installed_provider = self.live(operator.PROVIDER)
        result = self.run_operator('apply')
        self.assertEqual(result['result'], 'GO_ERP_DESKTOP_SYNC_INSTALLED')
        self.assertEqual(self.live(changed), '<?php // new version with expenses\n')
        self.assertEqual(self.live(operator.PROVIDER), installed_provider)
        self.assertEqual(self.run_operator('plan')['result'], 'GO_ERP_DESKTOP_SYNC_FILES_ALREADY_INSTALLED')
        rolled = self.run_operator('rollback', backup=Path(result['backup']))
        self.assertEqual(rolled['result'], 'GO_ERP_DESKTOP_SYNC_ROLLED_BACK')
        self.assertEqual(self.live(changed), '<?php // new ' + changed + '\n', 'The earlier version is back')
        self.assertEqual(self.live(operator.PROVIDER), installed_provider)

    def test_a_backup_from_the_first_installer_version_still_rolls_back(self):
        result = self.run_operator('apply')
        backup = self.backups / 'first-format'
        backup.mkdir(mode=0o700)
        (backup / 'provider.before').write_bytes(PROVIDER.encode())
        manifest = json.loads((Path(result['backup']) / 'manifest.json').read_text())
        manifest['format'] = 1
        for row in manifest['files'].values():
            row.pop('index')
        (backup / 'manifest.json').write_text(json.dumps(manifest))
        rolled = self.run_operator('rollback', backup=backup)
        self.assertEqual(rolled['result'], 'GO_ERP_DESKTOP_SYNC_ROLLED_BACK')
        self.assertTrue(all(self.live(name) is None for name in operator.NEW_FILES))
        self.assertEqual(self.live(operator.PROVIDER), PROVIDER)

    def test_the_route_line_goes_after_the_go_services_routes_with_the_same_indent(self):
        added = operator.add_route_line(PROVIDER.encode()).decode().splitlines()
        index = next(i for i, line in enumerate(added) if 'desktop_sync.php' in line)
        self.assertIn('go_services.php', added[index - 1])
        self.assertEqual(added[index], '            ' + operator.LINE)
        self.assertIsNone(operator.add_route_line('\n'.join(added).encode()), 'Never added twice')


if __name__ == '__main__':
    unittest.main()
