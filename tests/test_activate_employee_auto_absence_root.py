"""Turning on the automatic absence: the folder the command reads, its lock, and the timer that runs it."""
import calendar
import datetime
import fcntl
import grp
import importlib.util
import json
import os
from pathlib import Path
import pwd
import shutil
import stat
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('auto_absence', ROOT / 'deployment/activate_employee_auto_absence_root.py')
activator = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(activator)

READY = 'Employee automatic absence: {"ready":true,"considered":6,"recorded":0,"skipped":6,"errors":0}\n'
NOT_READY = 'Employee automatic absence is not activated or its attendance schema is not ready; no records changed.\n'
TODAY = datetime.date(2026, 11, 1)


class FakeSystemd:
    def __init__(self, folder):
        self.folder = folder
        self.calls = []
        self.active = False

    def __call__(self, arguments):
        self.calls.append(' '.join(arguments))
        timer = activator.NAME + '.timer'
        if arguments == ['is-active', timer]:
            return (0, 'active\n') if self.active else (3, 'inactive\n')
        if arguments == ['enable', '--now', timer]:
            if not (self.folder / timer).exists():
                return 1, 'Unit file does not exist.\n'
            self.active = True
            return 0, ''
        if arguments == ['disable', '--now', timer]:
            was = (self.folder / timer).exists()
            self.active = False
            return (0, '') if was else (1, 'Unit file does not exist.\n')
        if arguments == ['daemon-reload']:
            return 0, ''
        raise AssertionError('unexpected systemctl ' + ' '.join(arguments))


class AutomaticAbsenceActivationTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-absence-')
        base = Path(self.temporary.name)
        self.site = base / 'public_html'
        for name in (activator.SERVICE_CODE, activator.COMMAND_CODE):
            (self.site / name).parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(str(ROOT / name), str(self.site / name))
        (self.site / 'artisan').write_text('#!/usr/bin/env php\n')
        self.folder = base / 'employee-auto-absence-backups'
        self.units = base / 'systemd'
        self.units.mkdir()
        self.user = pwd.getpwuid(os.getuid()).pw_name
        self.systemd = FakeSystemd(self.units)
        self.output = READY
        self.artisan_calls = []

    def tearDown(self):
        self.temporary.cleanup()

    def artisan(self, arguments):
        self.artisan_calls.append(arguments)
        return (0 if 'ready":true' in self.output else 1), self.output

    def plan(self):
        return activator.plan(self.folder, self.units, self.site, self.user, TODAY, self.systemd, root_uid=os.getuid())

    def apply(self, starts_on=None, today=TODAY):
        return activator.apply(self.folder, self.units, self.site, '/opt/php', self.user, today, self.artisan, self.systemd,
                               starts_on=starts_on, root_uid=os.getuid(), root_gid=os.getgid())

    def rollback(self):
        return activator.rollback(self.folder, self.units, self.systemd, root_uid=os.getuid())

    def stops(self, reason, action):
        with self.assertRaises(activator.Stop) as stopped:
            action()
        self.assertEqual(str(stopped.exception), 'GO_ERP_AUTO_ABSENCE_' + reason)

    def mode(self, path):
        return stat.S_IMODE(path.lstat().st_mode)

    def test_the_plan_only_reads(self):
        self.assertEqual(self.plan(), {'result': 'GO_ERP_AUTO_ABSENCE_PLAN', 'state': 'off', 'starts_on': '2026-11-02', 'deadline': '22:00',
                                       'every_minutes': 5, 'timer': 'inactive', 'unit_files': []})
        self.assertFalse(self.folder.exists())
        self.assertEqual(self.artisan_calls, [])

    def test_turning_it_on_from_tomorrow(self):
        result = self.apply()
        self.assertEqual(result['result'], 'GO_ERP_AUTO_ABSENCE_ON')
        self.assertEqual((result['starts_on'], result['deadline'], result['already_on']), ('2026-11-02', '22:00', False))
        self.assertEqual(result['dry_run'], {'ready': True, 'considered': 6, 'recorded': 0, 'skipped': 6, 'errors': 0})
        self.assertEqual(self.artisan_calls, [['employees:auto-absence', '--dry-run']], 'Only the dry run is run here; the timer runs the rest')
        # What the command reads: root's folder in the site's group, its own lock, and the first day.
        self.assertEqual((self.mode(self.folder), self.folder.stat().st_gid), (0o750, pwd.getpwnam(self.user).pw_gid))
        self.assertEqual(self.mode(self.folder / 'install.lock'), 0o600)
        activation = self.folder / 'activation.json'
        self.assertEqual(self.mode(activation), 0o640)
        self.assertEqual(json.loads(activation.read_text()), {'starts_on': '2026-11-02'})
        self.assertLessEqual(activation.stat().st_size, 256)
        service = (self.units / (activator.NAME + '.service')).read_text()
        group = grp.getgrgid(pwd.getpwnam(self.user).pw_gid).gr_name
        for line in ('User=' + self.user, 'Group=' + group, 'WorkingDirectory=' + str(self.site), 'ExecStart=/opt/php artisan employees:auto-absence'):
            self.assertIn(line + '\n', service)
        self.assertIn('OnCalendar=*:0/5\n', (self.units / (activator.NAME + '.timer')).read_text())
        self.assertEqual(self.systemd.calls[-3:], ['daemon-reload', 'enable --now go-erp-employee-auto-absence.timer',
                                                   'is-active go-erp-employee-auto-absence.timer'])
        self.assertEqual(self.plan()['state'], 'on')

    def test_running_it_again_changes_nothing(self):
        self.apply()
        calls = len(self.systemd.calls)
        again = self.apply(today=TODAY + datetime.timedelta(days=3))
        self.assertTrue(again['already_on'])
        self.assertEqual(again['starts_on'], '2026-11-02', 'The first day stays as it was')
        self.assertNotIn('daemon-reload', self.systemd.calls[calls:])

    def test_a_chosen_first_day(self):
        self.assertEqual(self.apply(starts_on='2026-11-05')['starts_on'], '2026-11-05')
        self.assertEqual(self.apply(starts_on='2026-11-01')['starts_on'], '2026-11-01')
        self.assertEqual(json.loads((self.folder / 'activation.json').read_text()), {'starts_on': '2026-11-01'})
        self.stops('STARTS_ON', lambda: self.apply(starts_on='2026-02-30'))

    def test_not_ready_leaves_nothing_behind(self):
        self.output = NOT_READY
        self.stops('NOT_READY', self.apply)
        self.assertFalse(self.folder.exists())
        self.assertEqual(list(self.units.iterdir()), [])
        self.assertNotIn('enable --now go-erp-employee-auto-absence.timer', self.systemd.calls)

    def test_an_unreadable_dry_run_leaves_nothing_behind(self):
        self.output = 'PHP Fatal error: something\n'
        self.stops('DRY_RUN_UNREADABLE', self.apply)
        self.assertFalse(self.folder.exists())

    def test_errors_keep_the_earlier_setup(self):
        self.apply()
        self.output = READY.replace('"errors":0', '"errors":1')
        self.stops('DRY_RUN_ERRORS', lambda: self.apply(starts_on='2026-11-09'))
        self.assertEqual(json.loads((self.folder / 'activation.json').read_text()), {'starts_on': '2026-11-02'})
        self.assertTrue((self.folder / 'install.lock').exists())

    def test_turning_it_off(self):
        self.apply()
        result = self.rollback()
        self.assertEqual(result, {'result': 'GO_ERP_AUTO_ABSENCE_OFF', 'was_on_since': '2026-11-02', 'recorded_absences': 'kept',
                                  'unit_files_removed': ['go-erp-employee-auto-absence.timer', 'go-erp-employee-auto-absence.service']})
        self.assertFalse((self.folder / 'activation.json').exists())
        self.assertEqual(list(self.units.iterdir()), [])
        self.assertEqual(self.plan()['state'], 'off')
        self.assertEqual(self.rollback()['unit_files_removed'], [], 'Turning it off twice is fine')

    def test_a_folder_others_could_change_stops_it(self):
        self.folder.mkdir(mode=0o755)
        os.chmod(str(self.folder), 0o755)
        self.stops('FOLDER_OWNERSHIP', self.apply)
        self.stops('FOLDER_OWNERSHIP', self.plan)

    def test_the_site_code_must_read_this_folder(self):
        service = self.site / activator.SERVICE_CODE
        service.write_text(service.read_text().replace('/employee-auto-absence-backups/activation.json', '/elsewhere/activation.json'))
        self.stops('CODE_READS_ANOTHER_FOLDER', self.apply)

    def test_a_running_job_is_waited_for(self):
        self.apply()
        fd = os.open(str(self.folder / 'install.lock'), os.O_RDONLY)
        try:
            fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
            with self.assertRaises(activator.Stop) as stopped:
                with activator.Held(self.folder / 'install.lock', wait=0):
                    pass
            self.assertEqual(str(stopped.exception), 'GO_ERP_AUTO_ABSENCE_STILL_RUNNING')
        finally:
            os.close(fd)

    def test_the_operating_day_is_cairo_from_six_to_six(self):
        at = lambda text: calendar.timegm(datetime.datetime.strptime(text, '%Y-%m-%d %H:%M').timetuple())
        self.assertEqual(activator.operating_day(at('2026-11-01 03:30')), datetime.date(2026, 10, 31), '05:30 in Cairo')
        self.assertEqual(activator.operating_day(at('2026-11-01 04:30')), datetime.date(2026, 11, 1), '06:30 in Cairo')
        self.assertEqual(activator.operating_day(at('2026-11-01 21:30')), datetime.date(2026, 11, 1), '23:30 in Cairo')


if __name__ == '__main__':
    unittest.main()
