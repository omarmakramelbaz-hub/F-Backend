#!/usr/bin/env python3
"""The reviewed legacy activation pins change only for the fixed dashboard upgrade."""
import importlib.util
import pathlib
import unittest
from unittest.mock import patch

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('cairo_compat_fixture',ROOT/'tests/whatsapp_cairo_automatic_enable_test.py')
support=importlib.util.module_from_spec(spec);spec.loader.exec_module(support)
NS=support.NS
legacy_bytes=(ROOT/'deployment/enable_whatsapp_automatic_root.sh').read_bytes()


class LegacyCompatibilityTests(unittest.TestCase):
    def test_original_helper_bytes_still_exact_d30(self):
        self.assertEqual(NS['blob'](legacy_bytes),'d30c13bd13ca73e5aad54f558a259e18fb95147f')
        self.assertEqual(NS['LEGACY_BLOB'],NS['blob'](legacy_bytes))
    def test_only_original_five_ui_pins_change_in_place(self):
        marker='exec /usr/bin/python3 - "$@" <<\'PY\'\n';original={'__name__':'legacy_original_readonly'}
        text=legacy_bytes.decode();exec(compile(text.split(marker)[1][:-len('\nPY\n')],'legacy-original','exec'),original)
        actual=NS['legacy_program'](text)
        changed={p for p in original['SOURCE_PINS'] if actual['SOURCE_PINS'][p]!=original['SOURCE_PINS'][p]}
        self.assertEqual(changed,set(NS['ORIGINAL_DASHBOARD_LEGACY_PINS']))
        for p in changed:self.assertEqual(actual['SOURCE_PINS'][p],NS['DASHBOARD_PINS'][p])
        self.assertIs(actual['guard_sources'].__defaults__[0],actual['SOURCE_PINS'])
        self.assertIs(actual['orchestrate'].__defaults__[-1],actual['SOURCE_PINS'])
        self.assertEqual(actual['CAPTURE_PINS'],original['CAPTURE_PINS'])
        self.assertEqual(actual['HELPER_PINS'],original['HELPER_PINS'])
    def test_unknown_original_ui_pin_is_not_rebased(self):
        text=legacy_bytes.decode().replace('7047caefba93935a25029ea99715dac645d9c24d','0'*40)
        with self.assertRaisesRegex(NS['SafeError'],'CAIRO_LEGACY_DASHBOARD_PROFILE_UNEXPECTED'):NS['legacy_program'](text)
    def test_dashboard_guard_covers_exact17_and_cairo7_unchanged(self):
        self.assertEqual(len(NS['DASHBOARD_PINS']),17)
        self.assertEqual(len(NS['TARGET_PINS']),7)
        self.assertFalse(set(NS['DASHBOARD_PINS'])&set(NS['TARGET_PINS']))
        self.assertEqual(NS['TARGET_PINS']['app/Services/Dashboard/WhatsAppOrderWorkflow.php'],'6c63b91d7fb70130ae472cea291fa2cee88b27dd')


class InstalledProfileCompatibilityTests(support.CairoEnableTests):
    # Only these targeted tests are inherited into this runner.
    def test_17_target_bytes_are_accepted_without_changing_capture(self):
        original=dict(self.legacy['CAPTURE_PINS'])
        result=NS['profiles'](self.root,0,self.app,self.legacy,None)
        self.assertTrue(set(NS['DASHBOARD_PINS'])<=set(result));self.assertEqual(self.legacy['CAPTURE_PINS'],original)
    def test_unknown_new_ui_is_refused_before_bootstrap(self):
        p='public/js/dashboard-whatsapp-orders.js';(self.root/p).write_bytes(b'unknown peer edit')
        with self.assertRaisesRegex(NS['SafeError'],'CAIRO_DASHBOARD_RUNTIME_NEEDS_REVIEW: '+p):
            NS['profiles'](self.root,0,self.app,self.legacy,None)
        self.assertEqual(self.log,[])


if __name__=='__main__':
    suite=unittest.TestSuite()
    suite.addTests(unittest.defaultTestLoader.loadTestsFromTestCase(LegacyCompatibilityTests))
    for name in ('test_17_target_bytes_are_accepted_without_changing_capture','test_unknown_new_ui_is_refused_before_bootstrap'):
        suite.addTest(InstalledProfileCompatibilityTests(name))
    result=unittest.TextTestRunner().run(suite);raise SystemExit(not result.wasSuccessful())
