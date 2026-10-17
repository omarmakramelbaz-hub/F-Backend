"""Customer rollout preserves existing employee screens and route declarations.

Fixtures represent the two retained employee route variants. Tests need only the
checked-in manifest and git apply; no remote refs, PHP runtime or live app.
"""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest


PROJECT = Path(__file__).resolve().parents[1]
ROUTES_PATH = 'routes/branch_operations.php'
SHARED_EMPLOYEE_PATHS = {
    'public/dashboard/js/branch-operations.js',
    'public/dashboard/css/branch-operations.css',
    'resources/views/admin/branch_operations/index.blade.php',
}
CUSTOMER_PATHS = {
    'public/dashboard/js/customer-crm.js',
    'public/dashboard/css/customer-crm.css',
    'resources/views/admin/customer_crm/index.blade.php',
}
COMMON_ROUTES = (
    "        Route::get('/',[Operations::class,'index'])->name('index');"
    "Route::get('data',[Operations::class,'data'])->name('data');"
    "Route::post('save',[Operations::class,'save'])->name('save');\n"
)
CUSTOMER_BLOCK = """        if($module==='customers'){
            Route::post('directory-save',[Operations::class,'directorySave'])->name('directory-save');
            Route::post('consent',[Operations::class,'customerConsent'])->name('consent');
            Route::get('messaging-status',[Operations::class,'messagingStatus'])->name('messaging-status');
            Route::post('messaging-configure',[Operations::class,'configureMessaging'])->name('messaging-configure');
            Route::post('campaign-queue',[Operations::class,'queueCampaign'])->name('campaign-queue');
        }
"""


def route_fixture(attendance_rules):
    employee_methods = "'attendance',"
    if attendance_rules:
        employee_methods += "'attendance-rules'=>'attendanceRules',"
    employee_methods += "'entry','wallet','daily-notes'=>'dailyNotes','void-entry'=>'voidEntry','close','pay'"
    return (
        "<?php\n"
        "use App\\Http\\Controllers\\Dashboard\\BranchOperationsController as Operations;\n"
        "use Illuminate\\Support\\Facades\\Route;\n"
        "Route::middleware(['lang','IsAdmin'])->group(function(){\n"
        "    Route::get('admin/branch-operations/recover',[Operations::class,'recover'])->name('branch-operations.recover');\n"
        "    foreach(['customers','delivery-companies','employees'] as $module)Route::prefix('admin/'.$module)->name($module.'.')->group(function()use($module){\n"
        + COMMON_ROUTES
        + "        if($module==='employees'){\n"
        "            foreach(['statement','entries','export'] as $method)Route::get($method,[Operations::class,$method])->name($method);\n"
        + "            foreach([" + employee_methods + "] as $key=>$method){$path=is_int($key)?$method:$key;Route::post($path,[Operations::class,$method])->name($path);}\n"
        "        }\n"
        "    });\n"
        "});\n"
        "// Retained employee deployment overlay stays byte-for-byte unchanged.\n"
    ).encode()


class CustomerInstallerIsolationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.manifest = json.loads((PROJECT / 'deployment/customer_crm_manifest.json').read_text())

    def test_manifest_uses_dedicated_customer_screen_and_assets(self):
        paths = {entry['path'] for entry in self.manifest['files']}
        self.assertFalse(paths & SHARED_EMPLOYEE_PATHS, 'CRM deployment must preserve employee view, CSS and JS')
        self.assertTrue(CUSTOMER_PATHS <= paths, 'CRM assets and customer view must be explicitly installed')
        for relative in CUSTOMER_PATHS:
            with self.subTest(path=relative):
                self.assertTrue((PROJECT / relative).is_file())

    def test_narrow_route_patch_preserves_both_employee_variants_and_retries(self):
        entry = next(item for item in self.manifest['files'] if item['path'] == ROUTES_PATH)
        patch_text = entry['patch']
        # One adjacent route line is enough context; payroll method lists are not anchors.
        self.assertNotIn('attendance-rules', patch_text)
        self.assertNotIn("foreach(['statement'", patch_text)
        removed = [line for line in patch_text.splitlines() if line.startswith('-') and not line.startswith('---')]
        added = [line[1:] for line in patch_text.splitlines(keepends=True) if line.startswith('+') and not line.startswith('+++')]
        self.assertEqual([], removed, 'Customer routing adds a block without editing existing routes')
        self.assertEqual(CUSTOMER_BLOCK, ''.join(added))
        self.assertEqual(7, len(added))
        for attendance_rules in (False, True):
            with self.subTest(attendance_rules=attendance_rules), tempfile.TemporaryDirectory(prefix='crm-route-isolation-') as temporary:
                stage = Path(temporary)
                target = stage / ROUTES_PATH
                target.parent.mkdir()
                original = route_fixture(attendance_rules)
                target.write_bytes(original)
                patch = stage / 'customer-routes.patch'
                patch.write_text(patch_text)

                def apply(*flags):
                    return subprocess.run(['git', 'apply', *flags, '--whitespace=nowarn', str(patch)], cwd=stage, capture_output=True)

                check = apply('--check')
                self.assertEqual(0, check.returncode, check.stderr.decode())
                result = apply()
                self.assertEqual(0, result.returncode, result.stderr.decode())
                installed = target.read_bytes()
                self.assertEqual(original.replace(COMMON_ROUTES.encode(), (COMMON_ROUTES + CUSTOMER_BLOCK).encode(), 1), installed)
                self.assertEqual(1, installed.count(CUSTOMER_BLOCK.encode()))
                self.assertEqual(original, installed.replace(CUSTOMER_BLOCK.encode(), b'', 1))

                # The installer recognizes an installed patch through reverse --check.
                replay = apply('--reverse', '--check')
                self.assertEqual(0, replay.returncode, replay.stderr.decode())
                self.assertNotEqual(0, apply('--check').returncode, 'Forward replay must not create duplicate customer routes')
                self.assertEqual(installed, target.read_bytes(), 'Both retry probes are read-only')
                undo = apply('--reverse')
                self.assertEqual(0, undo.returncode, undo.stderr.decode())
                self.assertEqual(original, target.read_bytes(), 'Undo preserves all pre-existing employee route bytes')


if __name__ == '__main__':
    unittest.main()
