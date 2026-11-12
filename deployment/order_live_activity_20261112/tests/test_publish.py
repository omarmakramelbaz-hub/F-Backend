import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

PACKAGE = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('activity_publish', PACKAGE / 'publish.py')
publish = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publish)

class PublicationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'application'
        self.root.mkdir()
        self.backup = Path(self.temp.name) / 'backup'
        fixtures = {
            'app/Providers/AppServiceProvider.php': '<?php\nnamespace App\\Providers;\nclass AppServiceProvider {\n    public function boot()\n    {\n        // existing boot actions\n    }\n}\n',
            'app/Console/Kernel.php': '<?php\nnamespace App\\Console;\nclass Kernel {\n    protected function schedule(Schedule $schedule)\n    {\n        // existing schedules\n    }\n}\n',
            'routes/api.php': '<?php\n// existing authenticated and guest routes\n',
            'app/Models/OrderLiveActivity.php': "<?php\nnamespace App\\Models;\nclass OrderLiveActivity {\n    protected $guarded = [];\n    protected $hidden = ['activity_id_hash', 'activity_token', 'fcm_token', 'token_hash', 'fcm_token_hash'];\n    protected $casts = ['expires_at' => 'datetime', 'ended_at' => 'datetime', 'next_attempt_at' => 'datetime'];\n}\n",
            'app/Http/Controllers/Api/V1/User/OrderLiveActivityController.php': '<?php\n// different production controller; preserve every byte\n',
            'app/Events/OrderStatusUpdated.php': '<?php\n// different production event; preserve every byte\n',
        }
        for name, content in fixtures.items():
            target = self.root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(content)
        self.calls = []

    def runner(self, command, cwd):
        self.calls.append(command)
        return 'user/order-activity-subscriptions user/order-activity-subscriptions/unregister'

    def apply(self, runner=None):
        return publish.apply(self.root, PACKAGE, self.backup, os.getuid(), os.getgid(), php='php', runner=runner or self.runner)

    def files(self):
        return {str(p.relative_to(self.root)): p.read_bytes() for p in self.root.rglob('*') if p.is_file()}

    def test_incremental_apply_is_idempotent_and_rollback_restores_every_file(self):
        original = self.files()
        self.assertTrue(self.apply())
        self.assertFalse(self.apply())
        migrate = [call for call in self.calls if 'migrate' in call]
        self.assertEqual(len(migrate), 1)
        self.assertIn('--path=' + publish.MIGRATION, migrate[0])
        publish.rollback(self.root, self.backup)
        self.assertEqual(self.files(), original)

    def test_existing_production_model_controller_event_and_edits_are_untouched(self):
        target = self.root / 'routes/api.php'
        target.write_bytes(target.read_bytes() + b'\n// existing production customization\n')
        old = {name: value for name, value in self.files().items() if name.endswith(('OrderLiveActivity.php', 'OrderLiveActivityController.php', 'OrderStatusUpdated.php'))}
        self.apply()
        for name, value in old.items():
            self.assertEqual((self.root / name).read_bytes(), value)
        self.assertIn(b'existing production customization', target.read_bytes())
        self.assertNotIn('app/Models/OrderLiveActivity.php', json.loads((self.backup / 'receipt.json').read_text()))

    def test_unknown_collision_at_a_new_path_refuses_without_mutation(self):
        target = self.root / 'app/Models/OrderActivitySubscription.php'
        target.write_bytes(b'<?php // unknown customization\n')
        before = self.files()
        with self.assertRaisesRegex(RuntimeError, 'BASELINE_MISMATCH'):
            self.apply()
        self.assertEqual(self.files(), before)
        self.assertFalse(self.backup.exists())

    def test_failed_activation_restores_files(self):
        before = self.files()
        def fail(command, cwd):
            if 'migrate' in command:
                raise RuntimeError('simulated migration failure')
            return self.runner(command, cwd)
        with self.assertRaisesRegex(RuntimeError, 'simulated migration'):
            self.apply(fail)
        self.assertEqual(self.files(), before)

    def test_symlinked_destination_is_rejected(self):
        (self.root / 'app/Models/OrderActivitySubscription.php').symlink_to(self.root / 'routes/api.php')
        with self.assertRaisesRegex(RuntimeError, 'UNSAFE_FILE'):
            self.apply()

    def test_previously_published_imported_observer_is_not_duplicated(self):
        name = 'app/Providers/AppServiceProvider.php'
        target = self.root / name
        before = target.read_text().replace('// existing boot actions', 'Order::observe(\\App\\Observers\\OrderLiveActivityObserver::class);')
        target.write_text(before)
        self.apply()
        self.assertEqual(target.read_text(), before)

    def test_incomplete_existing_subscription_routes_refuse_before_writing(self):
        target = self.root / 'routes/api.php'
        target.write_text('<?php\n// OrderActivitySubscriptionController::class\n')
        before = self.files()
        with self.assertRaisesRegex(RuntimeError, 'INCOMPLETE_SUBSCRIPTION_ROUTES'):
            self.apply()
        self.assertEqual(self.files(), before)

    def test_closing_php_tag_and_different_legacy_formatting_are_supported(self):
        target = self.root / 'routes/api.php'
        target.write_text('<?php\n// existing routes\n?>\n')
        self.apply()
        self.assertIn('?>\n\n<?php\n', target.read_text())
        self.assertIn("'auth:api'", target.read_text())

    @unittest.skipUnless(shutil.which('php'), 'PHP is provided by the CI runtime')
    def test_every_proposed_production_php_file_passes_real_syntax_validation(self):
        for name, (_, after) in publish.plan(self.root, PACKAGE).items():
            with self.subTest(name=name):
                path = Path(self.temp.name) / 'lint.php'
                path.write_bytes(after)
                result = subprocess.run(['php', '-l', str(path)], capture_output=True)
                self.assertEqual(result.returncode, 0, result.stdout.decode() + result.stderr.decode())

if __name__ == '__main__':
    unittest.main()
