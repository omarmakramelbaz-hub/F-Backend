"""Immutable approved payment bytes plus a navigation-only payload overlay."""
import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[1]


def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value)
    return value


def git(*args):
    return subprocess.check_output(['git',*args],cwd=ROOT)


class PaymentNavigationIdentityTests(unittest.TestCase):
    def test_approved_payment_then_nav_overlay_preserves_original_hold_and_source_tree(self):
        m=module('payment_nav_identity_entry',ROOT/'deployment/upgrade_go_erp_payment_nav_successor_root.py')
        p=module('payment_nav_identity_prior',ROOT/'deployment/upgrade_go_erp_program_watcher_root.py')
        a=module('payment_nav_identity_helper',ROOT/'deployment/go_erp_payment_nav_application_patch.py')
        s=module('payment_nav_identity_scanner',ROOT/'deployment/refresh_desktop_dashboard_server_manifest.py')
        self.assertEqual(a.blob((ROOT/'deployment/go_erp_payment_nav_application_patch.py').read_bytes()),m.APPLICATION_BLOB)
        self.assertEqual((a.PAYMENT_BASE_SOURCE,a.PAYMENT_SOURCE,a.PAYMENT_PINS),(m.OLD_SOURCE,m.BEFORE_SOURCE,m.PAYMENT_PINS))
        header=git('cat-file','commit',m.PAYMENT_RELEASE).split(b'\n\n',1)[0]
        self.assertEqual([line[7:].decode() for line in header.splitlines() if line.startswith(b'parent ')],[m.OLD_CANDIDATE])
        receipt=json.loads(git('show',m.PAYMENT_RELEASE+':deployment/payment_delivery_fix.json'))
        self.assertEqual(receipt['base_revision'],m.OLD_CANDIDATE)
        self.assertEqual(set(receipt['files']),set(m.PAYMENT_PINS))
        before=s.scan(ROOT);self.assertEqual(s.digest(before),m.OLD_SOURCE)
        inventory=json.loads(git('show',p.COHORTS['preview35']['reference']+':'+p.VERSIONS[2]))['inventory']
        for name in p.TARGET_PINS:
            data=(ROOT/name).read_bytes();mode=git('ls-tree',m.CORE,'--',name).decode().split()[0]
            inventory[name]={'authority':'live','present':True,'mode':mode,'bytes':len(data),'sha256':hashlib.sha256(data).hexdigest()}
        fingerprint=lambda rows:hashlib.sha256(json.dumps(rows,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()).hexdigest()
        self.assertEqual(fingerprint(inventory),m.OLD_INVENTORY)
        after=copy.deepcopy(before)
        for name,(old_blob,new_blob) in m.PAYMENT_PINS.items():
            old=(ROOT/name).read_bytes();new=git('show',m.PAYMENT_RELEASE+':'+name)
            self.assertEqual((a.blob(old),a.blob(new)),(old_blob,new_blob))
            self.assertEqual(hashlib.sha256(old).hexdigest(),receipt['files'][name]['before'])
            self.assertEqual(hashlib.sha256(new).hexdigest(),receipt['files'][name]['after'])
            after[name]={'blob':new_blob,'sha256':hashlib.sha256(new.replace(b'\r\n',b'\n')).hexdigest()}
            inventory[name].update(bytes=len(new),sha256=hashlib.sha256(new).hexdigest())
        self.assertEqual(s.digest(after),m.BEFORE_SOURCE)
        nav=ROOT/'deployment/program-navigation/dashboard-navigation.js'
        self.assertEqual(a.blob(nav.read_bytes()),m.AFTER_NAV)
        after[m.NAV]=s.file_record(nav)
        inventory[m.NAV].update(bytes=nav.stat().st_size,sha256=hashlib.sha256(nav.read_bytes()).hexdigest())
        self.assertEqual(s.digest(after),m.NEW_SOURCE)
        self.assertEqual(fingerprint(inventory),m.NEW_INVENTORY)
        self.assertEqual(len(inventory),2554)
        self.assertEqual(s.digest(s.scan(ROOT)),m.OLD_SOURCE,'Repository production paths must remain the original prepared tree.')


if __name__=='__main__':unittest.main()
