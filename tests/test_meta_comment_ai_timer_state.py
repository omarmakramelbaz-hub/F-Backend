"""Parse real systemctl property output fixtures; never invoke or modify systemd."""
import importlib.util
import pathlib
import tempfile
import types
import unittest
from unittest import mock

PROJECT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("comment_ai_timer_state", PROJECT / "deployment/update_meta_comment_ai.py")
updater = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(updater)


class TimerStateTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.temporary.name)
        self.service, self.timer = updater.UNIT_NAMES

    def tearDown(self):
        self.temporary.cleanup()

    def properties(self, name, **changes):
        values = {
            "LoadState": "loaded",
            "FragmentPath": str(self.root / name),
            "DropInPaths": "",
            "UnitFileState": "static" if name == self.service else "enabled",
            "ActiveState": "inactive" if name == self.service else "active",
        }
        values.update(changes)
        return values

    @staticmethod
    def output(properties, returncode=0):
        raw = "".join(f"{key}={value}\n" for key, value in properties.items()).encode()
        return types.SimpleNamespace(returncode=returncode, stdout=raw, stderr=b"")

    def parse(self, service=None, timer=None, returncodes=(0, 0)):
        values = [
            self.output(service if service is not None else self.properties(self.service), returncodes[0]),
            self.output(timer if timer is not None else self.properties(self.timer), returncodes[1]),
        ]
        with mock.patch.object(updater, "UNIT_ROOT", self.root), mock.patch.object(updater.subprocess, "run", side_effect=values) as run:
            result = updater.timer_state()
        self.assertEqual([call.args[0][2] for call in run.call_args_list], [self.service, self.timer])
        for call in run.call_args_list:
            self.assertEqual(call.args[0][:2], ["systemctl", "show"])
            self.assertEqual(call.args[0][3:], ["--property=LoadState", "--property=FragmentPath", "--property=DropInPaths", "--property=UnitFileState", "--property=ActiveState"])
            self.assertEqual(call.kwargs, {"capture_output": True, "timeout": 30})
        self.assertEqual(set(result), {self.service, self.timer})
        self.assertTrue(all(type(value) is bool for unit in result.values() for value in unit.values()))
        return result

    def test_oneshot_activating_is_running_while_timer_is_enabled_and_active(self):
        result = self.parse(service=self.properties(self.service, ActiveState="activating"))
        self.assertEqual(result[self.service], {"enabled": False, "active": True})
        self.assertEqual(result[self.timer], {"enabled": True, "active": True})

    def test_oneshot_deactivating_preserves_preexisting_work_for_rollback(self):
        result = self.parse(service=self.properties(self.service, ActiveState="deactivating"))
        self.assertEqual(result[self.service], {"enabled": False, "active": True})

    def test_finished_and_failed_oneshots_are_not_running(self):
        for active in ("inactive", "failed"):
            with self.subTest(active=active):
                self.assertEqual(self.parse(service=self.properties(self.service, ActiveState=active))[self.service], {"enabled": False, "active": False})

    def test_active_service_is_preserved(self):
        self.assertTrue(self.parse(service=self.properties(self.service, ActiveState="active"))[self.service]["active"])

    def test_timer_transitions_remain_rejected(self):
        for active in ("activating", "deactivating"):
            with self.subTest(active=active), self.assertRaisesRegex(RuntimeError, "UNREVIEWED_TIMER_STATE"):
                self.parse(timer=self.properties(self.timer, ActiveState=active))

    def test_unknown_or_unexpected_active_states_remain_rejected(self):
        for name, state in ((self.service, "reloading"), (self.service, "mystery"), (self.timer, "maintenance"), (self.timer, "")):
            changes = {"service" if name == self.service else "timer": self.properties(name, ActiveState=state)}
            with self.subTest(name=name, state=state), self.assertRaisesRegex(RuntimeError, "UNREVIEWED_TIMER_STATE"):
                self.parse(**changes)

    def test_unreviewed_enable_states_remain_rejected(self):
        for name in (self.service, self.timer):
            for enabled in ("masked", "enabled-runtime", "indirect", "mystery"):
                changes = {"service" if name == self.service else "timer": self.properties(name, UnitFileState=enabled)}
                with self.subTest(name=name, enabled=enabled), self.assertRaisesRegex(RuntimeError, "UNREVIEWED_TIMER_STATE"):
                    self.parse(**changes)

    def test_timer_enable_and_active_flags_are_separate(self):
        result = self.parse(timer=self.properties(self.timer, UnitFileState="disabled", ActiveState="inactive"))
        self.assertEqual(result[self.timer], {"enabled": False, "active": False})
        result = self.parse(timer=self.properties(self.timer, UnitFileState="enabled", ActiveState="inactive"))
        self.assertEqual(result[self.timer], {"enabled": True, "active": False})

    def test_dropins_and_foreign_fragments_remain_rejected_for_both_units(self):
        for name in (self.service, self.timer):
            for extra in ({"DropInPaths": f"/etc/systemd/system/{name}.d/override.conf"}, {"FragmentPath": f"/usr/lib/systemd/system/{name}"}):
                changes = {"service" if name == self.service else "timer": self.properties(name, **extra)}
                with self.subTest(name=name, extra=extra), self.assertRaisesRegex(RuntimeError, "UNREVIEWED_TIMER_UNIT"):
                    self.parse(**changes)

    def test_missing_units_are_disabled_and_inactive_even_with_systemctl_exit_one(self):
        missing = {"LoadState": "not-found", "FragmentPath": "", "DropInPaths": "", "UnitFileState": "", "ActiveState": "inactive"}
        result = self.parse(service=missing, timer=missing, returncodes=(1, 1))
        self.assertEqual(result, {name: {"enabled": False, "active": False} for name in updater.UNIT_NAMES})

    def test_not_found_with_physical_unit_file_is_rejected(self):
        (self.root / self.service).write_text("[Service]\nType=oneshot\n")
        missing = self.properties(self.service, LoadState="not-found", FragmentPath="", UnitFileState="")
        with self.assertRaisesRegex(RuntimeError, "UNREVIEWED_TIMER_UNIT"):
            self.parse(service=missing)

    def test_not_found_with_fragment_or_dropin_is_rejected(self):
        for extra in ({"FragmentPath": str(self.root / self.service)}, {"DropInPaths": "/tmp/override.conf"}):
            missing = self.properties(self.service, LoadState="not-found", FragmentPath="", UnitFileState="")
            missing.update(extra)
            with self.subTest(extra=extra), self.assertRaisesRegex(RuntimeError, "UNREVIEWED_TIMER_UNIT"):
                self.parse(service=missing)

    def test_unloaded_or_failed_systemctl_reads_remain_rejected(self):
        for load in ("error", "masked", ""):
            with self.subTest(load=load), self.assertRaisesRegex(RuntimeError, "SYSTEMD_COMMAND_FAILED"):
                self.parse(service=self.properties(self.service, LoadState=load))
        with self.assertRaisesRegex(RuntimeError, "SYSTEMD_COMMAND_FAILED"):
            self.parse(returncodes=(1, 0))


if __name__ == "__main__":
    unittest.main()
