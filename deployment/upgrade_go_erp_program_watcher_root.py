#!/usr/bin/env python3
"""Pinned adapter for the reviewed transaction and desktop program section source.

No timer is changed here. The entry wrapper quiesces the worker first. The real
watcher lock stays held until both application and watcher commit or roll back.
"""
import argparse
import contextlib
import copy
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import sys
import types
import uuid

# Exact supported predecessor cohorts. Unknown deployed source or seed stops before writes.
COHORTS = {'preview35': {'inventory': '459ac45362218641669ba8686045b40ee7b7dee1b824d0f26038abf5684974a1',
               'minimum': 35,
               'pins': {'desktop_dashboard_server_preflight.py': '4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
                        'diagnose_desktop_dashboard_current_source.py': 'e7a7a8271481013a1c16167d6f7a6ff429687ef5',
                        'go_erp_current_candidate_ci.yml': 'af4d9bb97572c84e26cb6750d380adde2f7e9423',
                        'go_erp_live_source.py': '222f437677fbda2d57e30cb04c281d73361f656c',
                        'go_erp_live_updates.py': '89cac9b804f2fdb32444e9ad42cfbe05aed4098e',
                        'publish_go_erp_update.py': 'f9a7353b0705383844a576ab6d0f5b1662912bfb',
                        'verify_go_erp_current_candidate_ci.py': 'fb2c6ee364fae14062148846b654df8c96bba0de'},
               'reference': 'aaeaebe9e9cfb67a038f9b408800189ccdb96631',
               'runtime': 'policy-dad7c46ea265a4e776bf0cea7da2bf5800e2a456700c533264997ccbab8851a4',
               'seed': '02dedf4d1f07a33349b66501806778cb330874cb',
               'source': {'files': 1327,
                          'format': 1,
                          'framework': '8.83.29',
                          'sha256': '39ea59e8027d71cb5282c7b7e0fffa23c30361f56f359688777cee1458742b48'}},
 'wire': {'inventory': '9ee220bdc61f167270ff9b6b890b01d6d8eeab83188e39dab99835e290cb9367',
          'minimum': 36,
          'pins': {'desktop_dashboard_server_preflight.py': '4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
                   'diagnose_desktop_dashboard_current_source.py': 'e7a7a8271481013a1c16167d6f7a6ff429687ef5',
                   'go_erp_current_candidate_ci.yml': 'af4d9bb97572c84e26cb6750d380adde2f7e9423',
                   'go_erp_live_source.py': '1077afd7962a6b9c7d62466c5eb2a93f0cc09b26',
                   'go_erp_live_updates.py': '740f85b23796fa33191dee97ee8b4ec1a3ac5a2e',
                   'publish_go_erp_update.py': 'f9a7353b0705383844a576ab6d0f5b1662912bfb',
                   'verify_go_erp_current_candidate_ci.py': 'fb2c6ee364fae14062148846b654df8c96bba0de'},
          'reference': 'feb46c4c1e1ece981ce7600935fd43e69e6f308a',
          'runtime': 'policy-a9470b2eb4210fa97d2dc574fe58fba2251d0824d91d88055a90a040c3d56a3a',
          'seed': 'feb46c4c1e1ece981ce7600935fd43e69e6f308a',
          'source': {'files': 1328,
                     'format': 1,
                     'framework': '8.83.29',
                     'sha256': '7f7726165392580539bf995a571e99136623b2b656bfba6cf8c9e1c83d7421b4'}}}
RAW_PINS = {'desktop_dashboard_server_preflight.py': '4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
 'diagnose_desktop_dashboard_current_source.py': 'e7a7a8271481013a1c16167d6f7a6ff429687ef5',
 'go_erp_current_candidate_ci.yml': 'af4d9bb97572c84e26cb6750d380adde2f7e9423',
 'go_erp_live_source.py': '222f437677fbda2d57e30cb04c281d73361f656c',
 'go_erp_live_updates.py': '89cac9b804f2fdb32444e9ad42cfbe05aed4098e',
 'publish_go_erp_update.py': 'f9a7353b0705383844a576ab6d0f5b1662912bfb',
 'verify_go_erp_current_candidate_ci.py': 'fb2c6ee364fae14062148846b654df8c96bba0de'}
RAW_SEED = '02dedf4d1f07a33349b66501806778cb330874cb'
NEW_SEED = 'cd35ee36d77bc35593cac3627a33307b71309325'
CORE = 'd3cef0a0f823d1f10814ff74ce0f4badf5c29670'
NEW_INVENTORY = '0068ecaeb994a7c7bd3916eeaa137a1c33e0f2ddf3f1e5b3807a35db61d56005'
NEW_SOURCE = {'format': 1, 'files': 1342, 'framework': '8.83.29', 'sha256': '13bf1542e2118724272545f70f8eb2bd113fc1fe475bbc9a378135528f6824ea'}
TARGET_PINS = {'app/Http/Controllers/Api/DesktopDashboardController.php': 'e25238fb15d942da90406ee7736fb30bb4fb1f0f', 'app/Http/Controllers/Dashboard/BranchOperationsController.php': 'a5acd206f08497a8b1d4b60bd471051a831c20a5', 'app/Http/Controllers/Dashboard/DesktopDashboardController.php': 'eeedc3d1884203bc952ccea81f1fcce1c185e8c7', 'app/Services/Dashboard/BranchPayroll.php': '8eaadd71de19db3ce6c6ca160a64a04bd8f3c398', 'app/Services/Dashboard/DesktopDashboardArchive.php': 'ebf55a7c83360223037ccaadfb97045d29b3c0ff', 'app/Services/Dashboard/DesktopDashboardDelta.php': '189629f39f45b36a93e51c3b2628280b95c02732', 'app/Services/Dashboard/DesktopDashboardDeltaCapacity.php': 'cdfc6c613ae5a60419fd568996b1a0693d39639f', 'app/Services/Dashboard/DesktopDashboardDeltaCodec.php': 'bc8ebe34c56f1e04669a375e2b0d9fb217b1040b', 'app/Services/Dashboard/DesktopDashboardDeltaLocal.php': '75326d931184565477c22d981dbea687495c5411', 'app/Services/Dashboard/DesktopDashboardHandoff.php': 'bcf6eb5889b9a8ee49f7054f141594e6d3f528dd', 'app/Services/Dashboard/DesktopDashboardImport.php': 'fe66c49134c9c37dbbf8881761795796e24865c1', 'app/Services/Dashboard/DesktopDashboardMedia.php': '3b56badd74206227ed10836bb21162af0a42771f', 'app/Services/Dashboard/DesktopDashboardMediaSeal.php': '235617f070fcc1cf2ce82907ef3ff3b88e976100', 'app/Services/Dashboard/EmployeeIdentity.php': 'a7f3875d23a9429dc833c02182cb8c33f63f8aab', 'app/Services/Dashboard/EmployeeRegulation.php': '43e617a255c3453926c05fc40eac536b5a57032d', 'database/migrations/2026_10_23_200000_add_employee_identity_and_regulation.php': '8149b74d303a3eb2e48330338f3561aa815d910c', 'desktop/archive.php': '0d9d3ee5ea42ce0ca7476c238211330a19a13180', 'desktop/database.php': '6717b51eefd0cfde5f74ca94e74f1db9ab393133', 'desktop/delta.php': 'baa32129bd42e1979e8b8ae0d850be6cf324ee05', 'desktop/import-diagnostic.php': '996420355e31ebcb45feb6f23f21affec89fe619', 'desktop/import.php': '2f84468d591716a32bc400fc886f274fc9cc6a67', 'desktop/snapshot-row-hashes.php': '59b0619069b04da7c3a7707fa3350da0ebbdcc54', 'desktop/verify.php': 'c3546cf0837f531cdc02a246893b66b8061359ae', 'public/dashboard/css/branch-operations.css': '66ec58a1361e87d65a0d4eec42432eaf75a94989', 'public/dashboard/dist/js/adminlte.js': '3710a9c76e062c909d31d81f92b48d0043a662d1', 'public/dashboard/js/branch-expenses.js': '95cd9c298663c45caef6da7e4c85b56101cd792f', 'public/dashboard/js/branch-operations.js': 'b0e3c59fde1dd1b8ab077c0249aefe1b89cbd32a', 'public/dashboard/js/branch-recipes.js': '99b1fa6cc29bb3accd96e97609f76666c1f8aa6c', 'public/dashboard/js/branch-shifts.js': 'c80e364d4e5e687576f21b085f1b904e1be0dc60', 'public/dashboard/js/branch-stock.js': 'e2e699b490ad4e7bd353b5887bbee4188739440b', 'public/dashboard/js/dashboard-spa.js': '04e705676211db6bb7bb729b468bbb6bf845ceb4', 'public/dashboard/js/desktop-client.js': '49a29a7436dd1edd064fd28545bb9c8f4c76191c', 'public/dashboard/js/desktop-social.js': '63f9f39d242916e79e86e9a7c2e3ae2e5afeb9e3', 'public/dashboard/js/desktop-sync-status.js': '8f3171d43a64ede5e23e201dfe6c92417740e748', 'public/dashboard/js/takeaway-pos.js': '11a9919aaba04836e5f4c0f9030ade002fdb9aa4', 'resources/views/admin/desktop-dashboard.blade.php': '4795545bea33735e1b0dd01653516f7523a79ff8', 'resources/views/admin/employees/regulation.blade.php': '2d9578661d17258fab6563bcbf8202160c423a3b', 'resources/views/admin/layouts/header.blade.php': '8275f3502a2a0d7a27da93ab6aad7b3f2dcd36b0', 'resources/views/admin/layouts/menu.blade.php': 'e65165a5362dc6e20939463132d123853677ab3d', 'routes/api.php': '16d776a16436821c5e5815dcbdd807f35cb0353f', 'routes/branch_operations.php': 'f18b5bce5b7d5d302f6372f9bdcd3e57a847c0da', 'routes/desktop_dashboard.php': '24581a1798a4669939eceabe71219474ce3c6796'}
BEFORE_PINS = {'preview35': {'app/Http/Controllers/Api/DesktopDashboardController.php': 'e2c4e15dc20cd3976d66c137d5b036744ca61f6d', 'app/Http/Controllers/Dashboard/BranchOperationsController.php': 'b8894d28790868492b8a33a0bde00a45e387b256', 'app/Http/Controllers/Dashboard/DesktopDashboardController.php': '0143d7f7e9b7565759fc2d61cc5a0540796a47e2', 'app/Services/Dashboard/BranchPayroll.php': 'd5dfd039c827e9805cc551a9131556ed7dd99fd1', 'app/Services/Dashboard/DesktopDashboardArchive.php': 'e01ceeb822d3ab4765c6241001816a227d05f4c0', 'app/Services/Dashboard/DesktopDashboardDelta.php': None, 'app/Services/Dashboard/DesktopDashboardDeltaCapacity.php': None, 'app/Services/Dashboard/DesktopDashboardDeltaCodec.php': None, 'app/Services/Dashboard/DesktopDashboardDeltaLocal.php': None, 'app/Services/Dashboard/DesktopDashboardHandoff.php': None, 'app/Services/Dashboard/DesktopDashboardImport.php': '0299aa22be6d60f1e16a2026cfbae6e25e96fc71', 'app/Services/Dashboard/DesktopDashboardMedia.php': '7db598002b82774176c2244da2bd2c17d391284c', 'app/Services/Dashboard/DesktopDashboardMediaSeal.php': None, 'app/Services/Dashboard/EmployeeIdentity.php': None, 'app/Services/Dashboard/EmployeeRegulation.php': None, 'database/migrations/2026_10_23_200000_add_employee_identity_and_regulation.php': None, 'desktop/archive.php': '8ab868e6d158478c2ab36a586d718666e39c05ae', 'desktop/database.php': '1f31b59f3e1b7649ce2e668e1eeda5bb67ec346d', 'desktop/delta.php': None, 'desktop/import-diagnostic.php': 'd47d1a1e69cb21cbabe500e8695ff3203f617200', 'desktop/import.php': '8a2e148a91275ca748fd02ed9c577bd50526faeb', 'desktop/snapshot-row-hashes.php': None, 'desktop/verify.php': '67c34357a8afe71fc183be3e6a56044067499b15', 'public/dashboard/css/branch-operations.css': '30fc86c263e34129db4905dc7d1f5e8b2e536094', 'public/dashboard/dist/js/adminlte.js': 'ddbd13af09eec3db15d615ddb5010e4ec779840a', 'public/dashboard/js/branch-expenses.js': '8106ce07fb5d620b51d2f318ece16052df31722e', 'public/dashboard/js/branch-operations.js': '40c99f029b25b9178931bc01b7b2ec71c1ff914f', 'public/dashboard/js/branch-recipes.js': '0d793abfc9e9a8d15f0c9b56251b6feb09254ac5', 'public/dashboard/js/branch-shifts.js': '63cd3901dcd09f7c9778d0d6e6b0c9acb0a2fd7d', 'public/dashboard/js/branch-stock.js': '9d7caa33bdb329c2be25556696c30ff359a9743f', 'public/dashboard/js/dashboard-spa.js': '28ec997789cfd0da239f69937bcce032eb0e8c88', 'public/dashboard/js/desktop-client.js': '78cf9fe3caaed088de4bd0c4ab62a2ab510572b0', 'public/dashboard/js/desktop-social.js': None, 'public/dashboard/js/desktop-sync-status.js': None, 'public/dashboard/js/takeaway-pos.js': '18b5ae3f0d876d3411e189c741e00bd40e83d984', 'resources/views/admin/desktop-dashboard.blade.php': None, 'resources/views/admin/employees/regulation.blade.php': None, 'resources/views/admin/layouts/header.blade.php': '06169f02987da7577421af4089cadfd9abaf087f', 'resources/views/admin/layouts/menu.blade.php': '28953576da6db9e15e4f8d6c41fd8dabc2d2636b', 'routes/api.php': '9ada39b13b25e68034b09f8bf9e759e5543d8498', 'routes/branch_operations.php': '198a13496f3c8fef6b13a17b79d4bad73cb12e3f', 'routes/desktop_dashboard.php': 'b65cb4d3b25230b5e0dd8eb025d5e016ede0a208'}, 'wire': {'app/Http/Controllers/Api/DesktopDashboardController.php': 'e2c4e15dc20cd3976d66c137d5b036744ca61f6d', 'app/Http/Controllers/Dashboard/BranchOperationsController.php': 'b8894d28790868492b8a33a0bde00a45e387b256', 'app/Http/Controllers/Dashboard/DesktopDashboardController.php': '0143d7f7e9b7565759fc2d61cc5a0540796a47e2', 'app/Services/Dashboard/BranchPayroll.php': 'd5dfd039c827e9805cc551a9131556ed7dd99fd1', 'app/Services/Dashboard/DesktopDashboardArchive.php': 'e01ceeb822d3ab4765c6241001816a227d05f4c0', 'app/Services/Dashboard/DesktopDashboardDelta.php': None, 'app/Services/Dashboard/DesktopDashboardDeltaCapacity.php': None, 'app/Services/Dashboard/DesktopDashboardDeltaCodec.php': None, 'app/Services/Dashboard/DesktopDashboardDeltaLocal.php': None, 'app/Services/Dashboard/DesktopDashboardHandoff.php': None, 'app/Services/Dashboard/DesktopDashboardImport.php': 'cfaf087fa902ccbaecd2c1add5fb9ade01d76ad0', 'app/Services/Dashboard/DesktopDashboardMedia.php': '7db598002b82774176c2244da2bd2c17d391284c', 'app/Services/Dashboard/DesktopDashboardMediaSeal.php': None, 'app/Services/Dashboard/EmployeeIdentity.php': None, 'app/Services/Dashboard/EmployeeRegulation.php': None, 'database/migrations/2026_10_23_200000_add_employee_identity_and_regulation.php': None, 'desktop/archive.php': '8ab868e6d158478c2ab36a586d718666e39c05ae', 'desktop/database.php': '1f31b59f3e1b7649ce2e668e1eeda5bb67ec346d', 'desktop/delta.php': None, 'desktop/import-diagnostic.php': '996420355e31ebcb45feb6f23f21affec89fe619', 'desktop/import.php': '2f84468d591716a32bc400fc886f274fc9cc6a67', 'desktop/snapshot-row-hashes.php': '59b0619069b04da7c3a7707fa3350da0ebbdcc54', 'desktop/verify.php': '67c34357a8afe71fc183be3e6a56044067499b15', 'public/dashboard/css/branch-operations.css': '30fc86c263e34129db4905dc7d1f5e8b2e536094', 'public/dashboard/dist/js/adminlte.js': 'ddbd13af09eec3db15d615ddb5010e4ec779840a', 'public/dashboard/js/branch-expenses.js': '8106ce07fb5d620b51d2f318ece16052df31722e', 'public/dashboard/js/branch-operations.js': '40c99f029b25b9178931bc01b7b2ec71c1ff914f', 'public/dashboard/js/branch-recipes.js': '0d793abfc9e9a8d15f0c9b56251b6feb09254ac5', 'public/dashboard/js/branch-shifts.js': '63cd3901dcd09f7c9778d0d6e6b0c9acb0a2fd7d', 'public/dashboard/js/branch-stock.js': '9d7caa33bdb329c2be25556696c30ff359a9743f', 'public/dashboard/js/dashboard-spa.js': '28ec997789cfd0da239f69937bcce032eb0e8c88', 'public/dashboard/js/desktop-client.js': '78cf9fe3caaed088de4bd0c4ab62a2ab510572b0', 'public/dashboard/js/desktop-social.js': None, 'public/dashboard/js/desktop-sync-status.js': None, 'public/dashboard/js/takeaway-pos.js': '18b5ae3f0d876d3411e189c741e00bd40e83d984', 'resources/views/admin/desktop-dashboard.blade.php': None, 'resources/views/admin/employees/regulation.blade.php': None, 'resources/views/admin/layouts/header.blade.php': '06169f02987da7577421af4089cadfd9abaf087f', 'resources/views/admin/layouts/menu.blade.php': '28953576da6db9e15e4f8d6c41fd8dabc2d2636b', 'routes/api.php': '9ada39b13b25e68034b09f8bf9e759e5543d8498', 'routes/branch_operations.php': '198a13496f3c8fef6b13a17b79d4bad73cb12e3f', 'routes/desktop_dashboard.php': 'b65cb4d3b25230b5e0dd8eb025d5e016ede0a208'}}
POLICY = 'historical-baseline-v2-native-program-center-seed-v1'
BASE_MODULE = 'upgrade_go_erp_preview31_watcher_root.py'
BASE_BLOB = 'ede9af30ea42ba53a5376d5e1cf634d9b3032f2a'
APPLICATION_MODULE = 'go_erp_program_application_patch.py'
APPLICATION_BLOB = '7b175762e9a25eac6124decd1ccd6354f73c5f79'
GATE_MODULE = 'go_erp_program_rollout_gate.py'
GATE_BLOB = 'f24125324966d271158d6fe96b5472ae6e0ef894'
EMPLOYEE_MODULE = 'go_erp_employee_migration.py'
EMPLOYEE_MODULE_BLOB = '53001e13ca68dbc10e43c246d5944cd4ce8cd3c5'
EMPLOYEE_PROBE_BLOB = '05c21ef76b94b2edc9980d1b0922e5a280e7e5a8'
EMPLOYEE_MIGRATION_BLOB = '8149b74d303a3eb2e48330338f3561aa815d910c'
MIGRATION_PATH = 'database/migrations/2026_10_23_200000_add_employee_identity_and_regulation.php'
# Selected only after reading exact configuration under the watcher lock.
OLD_SEED = COHORTS['preview35']['seed']
OLD_RUNTIME = COHORTS['preview35']['runtime']
OLD_SOURCE = COHORTS['preview35']['source']
OLD_INVENTORY = COHORTS['preview35']['inventory']
OLD_PINS = COHORTS['preview35']['pins']
ACTIVE_COHORT = 'preview35'
ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
SAFE = 9007199254740991
REV = re.compile(r'[a-f0-9]{40}\Z')
VERSIONS = ('desktop-pos/package.json', 'desktop-pos/package-lock.json',
            'deployment/go_erp_live_source_candidate.json')


class Stop(Exception):
    pass


def need(condition, reason):
    if not condition:
        raise Stop(reason)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def transaction_audit(directory, name, value):
    """Aggregate DDL facts separately from the source-only patch receipts."""
    fd = os.open(directory / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, 'wb', closefd=False) as output:
            output.write((json.dumps(value, sort_keys=True) + '\n').encode()); output.flush(); os.fsync(fd)
    finally:
        os.close(fd)


def load_pinned(path, expected):
    need(REV.fullmatch(expected) and expected != '0' * 40, 'RELEASE_PIN_PENDING')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid, before.st_gid,
             before.st_nlink, stat.S_IMODE(before.st_mode)) == (0, 0, 1, 0o644)
             and 0 < before.st_size <= 2 * 1024 ** 2, 'MODULE_UNSAFE')
        data = bytearray()
        while len(data) <= 2 * 1024 ** 2:
            part = os.read(fd, 65536)
            if not part:
                break
            data.extend(part)
        key = lambda item: (item.st_dev, item.st_ino, item.st_mode, item.st_uid,
                            item.st_gid, item.st_nlink, item.st_size, item.st_mtime_ns, item.st_ctime_ns)
        need(key(before) == key(os.fstat(fd)) == key(path.lstat())
             and len(data) == before.st_size and blob(data) == expected, 'MODULE_PIN_INVALID')
    finally:
        os.close(fd)
    module = types.ModuleType('reviewed_' + path.stem)
    module.__file__ = str(path)
    sys.modules[module.__name__] = module
    exec(compile(bytes(data), str(path), 'exec'), module.__dict__)
    return module


def adapt_payloads(raw, gate_code=None):
    need(set(raw) == set(RAW_PINS), 'PAYLOAD_ROSTER_INVALID')
    need(REV.fullmatch(NEW_SEED) and NEW_SEED != '0' * 40, 'RELEASE_PINS_PENDING')
    result = {}
    replacements = ((RAW_SEED.encode(), NEW_SEED.encode()),
        (b"'-native-import422-diagnostic-seed-v1'", b"'-native-program-center-seed-v1'"))
    for name, value in raw.items():
        need(isinstance(value, bytes) and blob(value) == RAW_PINS[name], 'PAYLOAD_PIN_INVALID')
        if name in ('go_erp_live_source.py', 'go_erp_live_updates.py'):
            for before, after in replacements:
                need(value.count(before) == 1, 'PAYLOAD_PATCH_CONTEXT_INVALID')
                value = value.replace(before, after)
        result[name] = value
    if gate_code is None:
        gate_code = Path(__file__).with_name(GATE_MODULE).read_bytes()
    need(blob(gate_code) == GATE_BLOB and GATE_BLOB != '0' * 40, 'GATE_PAYLOAD_PIN_INVALID')
    value = result['go_erp_live_updates.py']
    insertions = (
        (b'def main(arguments=None):', gate_code + b'\n\ndef main(arguments=None):'),
        (b'            previous = self.state.get(\'published\')',
         b"            if not program_gate_allows(self.work, desired, release):\n                program_gate_collect(self, files, run, desired, release)\n                return 'GO_ERP_PROGRAM_ACCEPTANCE_REQUIRED'\n            previous = self.state.get('published')"),
        (b"            desired['status'] = 'published'\n            self.save()",
         b"            desired['status'] = 'published'\n            self.save()\n            if not program_gate_retire(self.work, self.live, self.publisher):\n                return 'GO_ERP_PROGRAM_PUBLICATION_REQUIRES_REVIEW'"),
        (b"                        return 'GO_ERP_LIVE_UPDATE_CURRENT'",
         b"                        if not program_gate_retire(self.work, self.live, self.publisher):\n                            return 'GO_ERP_PROGRAM_PUBLICATION_REQUIRES_REVIEW'\n                        return 'GO_ERP_LIVE_UPDATE_CURRENT'"),
        (b"    def tick(self):\n        try:\n",
         b"    def tick(self):\n        try:\n            program_gate_retire(self.work, self.live, self.publisher)\n"),
    )
    for before, after in insertions:
        need(value.count(before) == 1, 'GATE_PATCH_CONTEXT_INVALID'); value = value.replace(before, after)
    result['go_erp_live_updates.py'] = value
    return result


def select_cohort(seed):
    matches = [name for name, value in COHORTS.items() if value['seed'] == seed]
    need(len(matches) == 1, 'CONFIGURATION_SEED_UNREVIEWED')
    return matches[0]


def configure_cohort(name, base, application):
    global ACTIVE_COHORT, OLD_SEED, OLD_RUNTIME, OLD_SOURCE, OLD_INVENTORY, OLD_PINS
    need(name in COHORTS, 'CONFIGURATION_COHORT_UNREVIEWED')
    cohort = COHORTS[name]
    ACTIVE_COHORT = name
    OLD_SEED, OLD_RUNTIME = cohort['seed'], cohort['runtime']
    OLD_SOURCE, OLD_INVENTORY, OLD_PINS = cohort['source'], cohort['inventory'], cohort['pins']
    base.OLD_SEED, base.OLD_RUNTIME = OLD_SEED, OLD_RUNTIME
    base.OLD_PINS, base.OLD_ROSTER = OLD_PINS, tuple(sorted(OLD_PINS))
    need(set(TARGET_PINS) == set(BEFORE_PINS[name]) and TARGET_PINS, 'RELEASE_PINS_PENDING')
    pairs = {path: (BEFORE_PINS[name][path], wanted) for path, wanted in TARGET_PINS.items()
             if BEFORE_PINS[name][path] != wanted}
    application.configure(OLD_SOURCE, NEW_SOURCE, pairs)


def candidate_metadata(seed, inventory, number):
    version = '0.4.4-preview.' + str(number)
    return {'format': 1, 'kind': 'go-erp-live-source-candidate',
        'repository': 'omarmakramelbaz-hub/F-Backend', 'branch': 'codex/go-erp-live-source-20261021',
        'baseRevision': seed, 'version': version, 'versionSequence': number,
        'inventorySha256': hashlib.sha256(json.dumps(inventory, ensure_ascii=False, sort_keys=True,
            separators=(',', ':'), allow_nan=False).encode()).hexdigest(), 'inventory': inventory,
        'authoritativeLiveFiles': {path: row['sha256'] for path, row in inventory.items() if row['authority'] == 'live'},
        'packagedFiles': {path: row['sha256'] for path, row in inventory.items() if row['present']},
        'retainedTrustedFiles': {path: row['sha256'] for path, row in inventory.items() if row['authority'] == 'trusted-base'},
        'removedFiles': [path for path, row in inventory.items() if not row['present']]}


class ReleaseGuard:
    """Accept only exact reviewed predecessor inventories and version-only candidates."""
    def __init__(self, base):
        self.base = base
        self.revision = None
        self.version = None
        self.number = None
        self.validate_release = None
        self.candidate_sources = {}
        self.candidate_inventories = {}

    def candidate(self, checkout, revision):
        b = self.base
        need(isinstance(revision, str) and REV.fullmatch(revision), 'REMOTE_REVISION_INVALID')
        references = [value['reference'] for value in COHORTS.values()]
        b.git(checkout, 'fetch', '--quiet', '--depth=1', '--no-tags', '--no-recurse-submodules',
              b.REMOTE, *references, revision)
        parents = [line[7:].decode() for line in b.git(checkout, 'cat-file', 'commit', revision)
                   .split(b'\n\n', 1)[0].splitlines() if line.startswith(b'parent ')]
        matching = [name for name, cohort in COHORTS.items() if parents == [cohort['seed']]]
        need(len(matching) == 1, 'REMOTE_PARENT_UNREVIEWED')
        cohort_name = matching[0]
        need(ACTIVE_COHORT == 'wire' or cohort_name == 'preview35', 'REMOTE_COHORT_UNREVIEWED')
        cohort = COHORTS[cohort_name]
        names = b.git(checkout, 'diff', '--name-only', '--no-renames', '-z',
                      cohort['reference'], revision, '--').split(b'\0')
        need(all(name.decode() in VERSIONS for name in names if name), 'REMOTE_CODE_UNREVIEWED')
        current = b.parse(b.git(checkout, 'show', revision + ':' + VERSIONS[2]))
        number = current.get('versionSequence')
        need(type(number) is int and cohort['minimum'] <= number <= SAFE, 'REMOTE_VERSION_UNREVIEWED')
        inventory = current.get('inventory')
        need(isinstance(inventory, dict) and current.get('inventorySha256') == cohort['inventory'],
             'REMOTE_INVENTORY_UNREVIEWED')
        need(current == candidate_metadata(cohort['seed'], inventory, number), 'REMOTE_METADATA_UNREVIEWED')
        for path in VERSIONS[:2]:
            expected = b.parse(b.git(checkout, 'show', cohort['reference'] + ':' + path))
            actual = b.parse(b.git(checkout, 'show', revision + ':' + path))
            expected['version'] = current['version']
            if path.endswith('package-lock.json'):
                expected['packages']['']['version'] = current['version']
            need(actual == expected, 'REMOTE_PACKAGE_UNREVIEWED')
        self.candidate_sources[revision] = cohort['source']
        self.candidate_inventories[revision] = cohort['inventory']
        return current['version'], number

    def __call__(self, checkout):
        b = self.base
        ref = 'refs/heads/codex/go-erp-live-source-20261021'
        raw = b.git(checkout, 'ls-remote', '--exit-code', b.REMOTE, ref, maximum=4096)
        match = re.fullmatch(rb'([a-f0-9]{40})\t' + ref.encode() + rb'\n', raw)
        need(match is not None, 'REMOTE_BRANCH_UNREVIEWED')
        revision = match[1].decode()
        if self.revision is None:
            self.version, self.number = self.candidate(checkout, revision)
            need(self.number >= 35, 'REMOTE_VERSION_UNREVIEWED')
            self.revision = revision
        need(revision == self.revision, 'REMOTE_BRANCH_CHANGED')


def migrated_state(base, guard, checkout, value, old_seed, new_seed, publication):
    base.checked_state(value, old_seed)
    need(old_seed == OLD_SEED and new_seed == NEW_SEED and guard.revision is not None,
         'STATE_SEED_UNREVIEWED')
    need(value.get('last_remote_sha') == guard.revision, 'REMOTE_HISTORY_UNREVIEWED')
    observed = value.get('observed')
    need(observed is None or isinstance(observed, dict) and observed.get('seedRevision') == OLD_SEED
         and observed.get('inventorySha256') == OLD_INVENTORY, 'OBSERVED_SOURCE_UNREVIEWED')
    sequence = value.get('sequence_floor', 0)
    for name in ('desired', 'published'):
        item = value.get(name)
        if item is None:
            continue
        need(isinstance(item, dict), 'CANDIDATE_STATE_UNREVIEWED')
        revision = item.get('commit')
        if name == 'desired':
            need(revision == guard.revision, 'UNPUBLISHED_CANDIDATE_REQUIRES_REVIEW')
            guard.candidate(checkout, revision)
            need(item.get('inventorySha256') == guard.candidate_inventories[revision], 'CANDIDATE_STATE_UNREVIEWED')
        else:
            # A previous accepted Preview35 publication is preserved in
            # history, but its reference must not be silently reinterpreted.
            old_version, old_number = guard.candidate(checkout, revision)
            need(item.get('inventorySha256') == guard.candidate_inventories[revision], 'CANDIDATE_STATE_UNREVIEWED')
            need(callable(guard.validate_release), 'RELEASE_VALIDATOR_UNAVAILABLE')
            release = guard.validate_release(item.get('release'))
            need(old_number <= guard.number and release.get('version') == old_version
                 and release.get('source_revision') == revision and release.get('source') == guard.candidate_sources[revision],
                 'PUBLISHED_HISTORY_UNREVIEWED')
            sequence = max(sequence, release['sequence'])
    floor = int(value['version_floor'].rsplit('.', 1)[1]) if value.get('version_floor') else 0
    need(guard.number <= value['counter'] and floor <= value['counter'], 'COUNTER_HIGH_WATER_INVALID')
    if publication is not None:
        version = re.fullmatch(r'0\.4\.4-preview\.([1-9][0-9]*)', publication.get('version', ''))
        need(version is not None and int(version[1]) <= value['counter'], 'PUBLIC_RELEASE_UNREVIEWED')
        accepted_version, _ = guard.candidate(checkout, publication.get('source_revision'))
        need(publication.get('source') == guard.candidate_sources[publication['source_revision']], 'PUBLIC_RELEASE_UNREVIEWED')
        need(accepted_version == publication['version'], 'PUBLIC_RELEASE_UNREVIEWED')
        sequence = max(sequence, publication['sequence'])
    result = copy.deepcopy(value)
    counter = max(value['counter'], floor, guard.number)
    result.update(base_revision=NEW_SEED, observed=None, desired=None, published=None,
        counter=counter, version_floor='0.4.4-preview.' + str(counter), sequence_floor=sequence)
    return result


@contextlib.contextmanager
def held_lock(utility, work):
    directory = utility.Directory(work, 0o700)
    fd = None
    try:
        fd = os.open('watcher.lock', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory.fd)
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and (before.st_uid, before.st_gid,
             before.st_nlink, stat.S_IMODE(before.st_mode)) == (0, 0, 1, 0o600), 'LOCK_UNSAFE')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Stop('WATCHER_BUSY') from None
        def verify():
            directory.verify()
            need(utility.exact_key(before) == utility.exact_key(os.fstat(fd))
                 == utility.exact_key(os.stat('watcher.lock', dir_fd=directory.fd, follow_symlinks=False)), 'LOCK_CHANGED')
        verify()
        yield fd, verify
        verify()
    finally:
        if fd is not None:
            os.close(fd)
        directory.close()


class LockedUtility:
    def __init__(self, original, work, verify):
        self.original, self.work, self.verify = original, work, verify
    def __getattr__(self, name):
        return getattr(self.original, name)
    @contextlib.contextmanager
    def watcher_lock(self, work):
        need(work == self.work, 'LOCK_SCOPE_INVALID')
        self.verify()
        yield self.verify
        self.verify()


def assert_quiescent():
    for name, accepted in [('go-erp-live-updates.timer', ('inactive',)),
                           ('go-erp-live-updates.service', ('inactive', 'failed'))]:
        result = subprocess.run(['/usr/bin/systemctl', 'show', name, '-p', 'ActiveState', '--value'],
            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            timeout=10, check=False)
        need(result.returncode == 0 and result.stdout.decode('ascii', errors='replace').strip() in accepted,
             'WORKER_NOT_QUIESCENT')


def require_no_pending(base, utility, work):
    directory = utility.Directory(work, 0o700)
    try:
        # A new migration must not make an unfinished previous transaction
        # invisible merely by adopting a different audit prefix.
        prefix = base.PREFIX
        try:
            for base.PREFIX in ('import422-seed-upgrade-', 'wire-hash-seed-upgrade-', 'program-seed-upgrade-'):
                need(base.pending_audit(utility, directory) is None, 'PRIOR_MIGRATION_REQUIRES_REVIEW')
        finally:
            base.PREFIX = prefix
        for name in os.listdir(directory.fd):
            if not re.fullmatch(r'(?:import422|wire-hash|program)-application-[a-f0-9]{32}', name):
                continue
            audit = utility.Directory(work / name, 0o700)
            try:
                roster = set(os.listdir(audit.fd))
                need('application-intent.json' not in roster or bool(roster.intersection({
                    'application-committed.json', 'application-rolled-back.json'})),
                    'PRIOR_MIGRATION_REQUIRES_REVIEW')
            finally:
                audit.close()
    finally:
        directory.close()


def verify_watcher_rollback(base, utility, config, state):
    """Independently recognize all old private slots before reverting source."""
    for parent, name, mode, expected in (
            (base.CONFIG.parent, base.CONFIG.name, 0o600, config),
            (WORK / 'state', 'current.json', 0o600, state)):
        directory = utility.Directory(parent, 0o700)
        try:
            actual = base.parse(utility.read_at(directory, name, base.MAX_STATE, mode))
            need(actual == expected, 'WATCHER_ROLLBACK_REQUIRES_REVIEW')
        finally:
            directory.close()
    directory = utility.Directory(base.UNIT.parent, 0o755)
    try:
        need(utility.read_at(directory, base.UNIT.name, 65536, 0o644)
             == base.unit_bytes(utility, base.RELEASES / OLD_RUNTIME, ROOT, WORK, base.CONFIG),
             'WATCHER_ROLLBACK_REQUIRES_REVIEW')
    finally:
        directory.close()
    base.checkout(WORK / 'checkout', OLD_SEED, utility)
    utility.effective_unit(base.UNIT, base.CONFIG, (base.RELEASES / OLD_RUNTIME,))


def run_current(base, utility, application, package, owner, lock_fd, verify, payloads, pins, guard):
    application.verify_current(ROOT, package, owner.pw_uid, owner.pw_gid, lock_fd)
    verify()
    result = base.upgrade(payloads, pins=pins, utility=LockedUtility(utility, WORK, verify),
        new_seed=NEW_SEED, core=CORE, old_pins=OLD_PINS, remote=guard)
    application.verify_current(ROOT, package, owner.pw_uid, owner.pw_gid, lock_fd)
    verify()
    result.update(kind='go-erp-program-watcher-upgrade', application_contents_changed=False)
    return result


def run_transaction(base, utility, application, package, owner, lock_fd, verify, payloads, pins, guard,
                    confirm_rollback, schema_migration=None):
    audit = WORK / ('program-application-' + uuid.uuid4().hex)
    os.mkdir(audit, 0o700)
    patch = application.ApplicationPatch(ROOT, package, audit, owner.pw_uid, owner.pw_gid, lock_fd)
    result = None
    watcher_started = False
    rollback_attempted = False
    database_changed = False
    application_changed = False
    try:
        patch.prepare(); verify(); patch.apply(); application_changed = True; patch.verify(); verify()
        if schema_migration is not None:
            database_changed = 'unknown'
            schema = schema_migration(audit)
            database_changed = schema.get('database_changed', 'unknown')
            transaction_audit(audit, 'employee-schema-result.json', schema)
            need(schema.get('ok') is True, 'EMPLOYEE_MIGRATION_REQUIRES_REVIEW')
            patch.verify(); verify()
        watcher_started = True
        result = base.upgrade(payloads, pins=pins, utility=LockedUtility(utility, WORK, verify),
            new_seed=NEW_SEED, core=CORE, old_pins=OLD_PINS, remote=guard)
        if result.get('ok') is not True:
            if result.get('rollback_complete') is False:
                try:patch.verify(); result['application_contents_changed'] = True
                except BaseException:result['application_contents_changed'] = 'unknown'
                result['database_changed'] = database_changed
                transaction_audit(audit, 'program-result.json', result)
                return result
            try:
                confirm_rollback()
            except BaseException:
                raise Stop('WATCHER_ROLLBACK_REQUIRES_REVIEW') from None
            rollback_attempted = True
            patch.rollback()
            application_changed = False
            result['application_contents_changed'] = False
            result['database_changed'] = database_changed
            transaction_audit(audit, 'program-result.json', result)
            return result
        patch.verify(); verify(); patch.commit()
        result.update(kind='go-erp-program-watcher-upgrade', application_contents_changed=True,
            next_version='0.4.4-preview.' + str(result['counter'] + 1), database_changed=database_changed,
            publication_held=True, published=False, timer_paused=True)
        result['worker_request'] = 'candidate'
        transaction_audit(audit, 'program-result.json', result)
        return result
    except BaseException as error:
        try:
            transaction_audit(audit, 'program-failed.json', {'ok':False,'database_changed':database_changed,
                'published':False,'schema_rollback_attempted':False})
        except BaseException:
            pass
        # DDL is not reversed with source/watcher slots. Preserve this fact
        # through every later rollback/commit failure.
        def failed(reason):
            problem = Stop(reason); problem.database_changed = database_changed
            problem.application_contents_changed = application_changed; return problem
        if result is not None and result.get('ok') is True:
            raise failed('APPLICATION_COMMIT_REQUIRES_REVIEW') from None
        if watcher_started:
            if result is None or str(error) == 'WATCHER_ROLLBACK_REQUIRES_REVIEW':
                raise failed('WATCHER_ROLLBACK_REQUIRES_REVIEW') from None
        if rollback_attempted:
            raise failed('APPLICATION_ROLLBACK_REQUIRES_REVIEW') from None
        try:
            rollback_attempted = True
            patch.rollback()
            application_changed = False
        except BaseException:
            application_changed = 'unknown'
            raise failed('APPLICATION_ROLLBACK_REQUIRES_REVIEW') from None
        error.database_changed = database_changed
        error.application_contents_changed = application_changed
        raise


def execute(package, apply=False, acceptance_receipt=None):
    need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524', 'ROOT_AND_HOST_REQUIRED')
    package = Path(package).absolute()
    need(package.resolve() == package, 'PACKAGE_PATH_UNSAFE')
    base = load_pinned(package / BASE_MODULE, BASE_BLOB)
    utility = base.load_utility(package / 'upgrade_go_erp_capacity_watcher_root.py')
    application = load_pinned(package / APPLICATION_MODULE, APPLICATION_BLOB)
    gate = load_pinned(package / GATE_MODULE, GATE_BLOB)
    employee = load_pinned(package / EMPLOYEE_MODULE, EMPLOYEE_MODULE_BLOB)
    expected = gate.program_gate_expected(CORE, NEW_SOURCE, NEW_INVENTORY)
    directory = utility.Directory(package / 'payload', 0o700)
    try:
        need(set(os.listdir(directory.fd)) == set(RAW_PINS), 'PAYLOAD_ROSTER_INVALID')
        raw = {name: utility.read_at(directory, name, utility.MAX_FILE, 0o600) for name in RAW_PINS}
    finally:
        directory.close()
    payloads = adapt_payloads(raw, (package / GATE_MODULE).read_bytes())
    pins = {name: blob(data) for name, data in payloads.items()}
    base.CORE, base.NEW_SEED = CORE, NEW_SEED
    base.PINS, base.ROSTER = pins, tuple(sorted(pins))
    base.EXPECTED_SOURCE, base.EXPECTED_INVENTORY = NEW_SOURCE, NEW_INVENTORY
    base.PREFIX = 'program-seed-upgrade-'
    guard = ReleaseGuard(base)
    base.migrated_state = lambda value, old_seed, new_seed, publication=None: migrated_state(
        base, guard, WORK / 'checkout', value, old_seed, new_seed, publication)
    with held_lock(utility, WORK) as (lock_fd, verify):
        assert_quiescent()
        try:
            require_no_pending(base, utility, WORK)
        except BaseException:
            raise Stop('PRIOR_MIGRATION_REQUIRES_REVIEW') from None
        cfg_dir = utility.Directory(base.CONFIG.parent, 0o700)
        state_dir = utility.Directory(WORK / 'state', 0o700)
        try:
            config = base.parse(utility.read_at(cfg_dir, base.CONFIG.name, 16384, 0o600))
            state = base.parse(utility.read_at(state_dir, 'current.json', base.MAX_STATE, 0o600))
        finally:
            state_dir.close(); cfg_dir.close()
        current_seed = config.get('base_revision') if isinstance(config, dict) else None
        cohort_name = select_cohort(current_seed) if current_seed != NEW_SEED else 'preview35'
        configure_cohort(cohort_name, base, application)
        base.checked_config(config, current_seed)
        base.checked_state(state, current_seed)
        base.checkout(WORK / 'checkout', current_seed, utility)
        owner = pwd.getpwnam('fasakha')
        def migrate(audit):
            return employee.execute(package, audit, EMPLOYEE_MIGRATION_BLOB, EMPLOYEE_PROBE_BLOB, True, lock_fd, verify)
        if current_seed == NEW_SEED:
            application.verify_current(ROOT, package, owner.pw_uid, owner.pw_gid, lock_fd)
            target = base.RELEASES / utility.runtime_name(payloads)
            current_runtime = utility.Directory(target, 0o755)
            try:
                utility.validate_runtime(current_runtime, pins)
                publisher = utility.module_at(current_runtime, 'publish_go_erp_update.py', pins['publish_go_erp_update.py'])
            finally:
                current_runtime.close()
            need(state['counter'] >= 35 and state.get('version_floor') is not None
                 and 35 <= int(state['version_floor'].rsplit('.', 1)[1]) <= state['counter'],
                 'CURRENT_HIGH_WATER_INVALID')
            if not apply:
                utility.effective_unit(base.UNIT, base.CONFIG, (target,))
                return {'format': 1, 'kind': 'go-erp-program-upgrade-check', 'ok': True,
                    'already_current': True, 'counter': state['counter'], 'rollback_complete': True,
                    'application_contents_changed': False, 'database_changed': False, 'credentials_disclosed': False}
            def read_publication():
                try:return publisher.validate_release(publisher.read_json(ROOT / 'storage/app/desktop-updates/current.json', root_owned=True))
                except FileNotFoundError:return None
            if gate.program_gate_completed(WORK, expected, state, read_publication):
                verify(); assert_quiescent()
                return {'format':1,'kind':'go-erp-program-already-completed','ok':True,'already_current':True,
                    'worker_request':'none','resume_previous_timer':True,'publication_held':False,'published':False,
                    'database_changed':False,'credentials_disclosed':False,'rollback_complete':True}
            if acceptance_receipt is not None:
                current_runtime = utility.Directory(target, 0o755)
                try:
                    worker = utility.module_at(current_runtime, 'go_erp_live_updates.py', pins['go_erp_live_updates.py'])
                    gate.program_gate_authorize(WORK, acceptance_receipt, expected, state,
                        lambda folder, run, desired: worker.verify_proof(folder, run, desired, publisher),read_publication)
                finally: current_runtime.close()
                verify(); assert_quiescent()
                return {'format':1,'kind':'go-erp-program-publication-authorized','ok':True,
                    'candidate':state['desired']['commit'],'publication_held':True,'published':False,
                    'worker_request':'authorized-publication',
                    'database_changed':False,'credentials_disclosed':False,'rollback_complete':True}
            audit = WORK / ('program-schema-check-' + uuid.uuid4().hex); os.mkdir(audit, 0o700)
            schema = migrate(audit)
            if schema.get('ok') is not True:
                error = Stop('EMPLOYEE_MIGRATION_REQUIRES_REVIEW'); error.database_changed = schema.get('database_changed', 'unknown'); raise error
            hold = gate.program_gate_prepare(WORK, expected)
            need(hold.get('kind') != 'go-erp-program-rollout-completed', 'COMPLETED_PUBLICATION_REQUIRES_REVIEW')
            return {'format':1,'kind':'go-erp-program-prepared','ok':True,'already_current':True,
                'counter':state['counter'],'publication_held':True,'published':False,'timer_paused':True,
                'worker_request':'candidate',
                'database_changed':schema['database_changed'],'credentials_disclosed':False,'rollback_complete':True}
        need(acceptance_receipt is None, 'PREPARE_REQUIRED_BEFORE_PUBLICATION')
        application.verify_before(ROOT, package, owner.pw_uid)
        guard(WORK / 'checkout')
        old_runtime = utility.Directory(base.RELEASES / OLD_RUNTIME, 0o755)
        try:
            utility.validate_runtime(old_runtime, OLD_PINS)
            publisher = utility.module_at(old_runtime, 'publish_go_erp_update.py', OLD_PINS['publish_go_erp_update.py'])
            guard.validate_release = publisher.validate_release
        finally:
            old_runtime.close()
        public = base.publication_snapshot(base.RELEASES / OLD_RUNTIME, ROOT, OLD_PINS, utility)
        next_state = migrated_state(base, guard, WORK / 'checkout', state, OLD_SEED, NEW_SEED, public)
        if not apply:
            return {'format': 1, 'kind': 'go-erp-program-upgrade-check', 'ok': True,
                'counter': state['counter'], 'next_version': '0.4.4-preview.' + str(next_state['counter'] + 1),
                'application_contents_changed': False, 'database_changed': False,
                'credentials_disclosed': False, 'rollback_complete': True}
        gate.program_gate_prepare(WORK, expected)
        return run_transaction(base, utility, application, package, owner, lock_fd, verify, payloads, pins, guard,
            lambda: verify_watcher_rollback(base, utility, config, state), migrate)


def main(arguments=None):
    parser = argparse.ArgumentParser()
    parser.add_argument('--package', required=True)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--prepare', action='store_true')
    mode.add_argument('--publish', action='store_true')
    mode.add_argument('--check', action='store_true')
    parser.add_argument('--acceptance-receipt')
    args = parser.parse_args(arguments)
    parser.error('--publish requires --acceptance-receipt') if args.publish and not args.acceptance_receipt else None
    parser.error('--acceptance-receipt is only valid with --publish') if args.acceptance_receipt and not args.publish else None
    try:
        report = execute(args.package, args.prepare or args.publish, args.acceptance_receipt)
    except BaseException as error:
        reason = str(error) if re.fullmatch(r'[A-Z0-9_]{1,100}', str(error)) else 'PRIVATE_FAILURE'
        report = {'format': 1, 'kind': 'go-erp-program-watcher-upgrade', 'ok': False,
            'reason': reason, 'credentials_disclosed': False, 'database_changed': getattr(error, 'database_changed', False),
            'application_contents_changed':getattr(error,'application_contents_changed',False),
            'published':False,
            'rollback_complete': reason not in ('APPLICATION_ROLLBACK_REQUIRES_REVIEW', 'APPLICATION_COMMIT_REQUIRES_REVIEW',
                'WATCHER_ROLLBACK_REQUIRES_REVIEW', 'PRIOR_MIGRATION_REQUIRES_REVIEW', 'LOCK_CHANGED', 'EMPLOYEE_MIGRATION_REQUIRES_REVIEW')}
    print(json.dumps(report, sort_keys=True))
    return 0 if report.get('ok') is True else 78 if report.get('rollback_complete') is False else 75 if report.get('reason') == 'WATCHER_BUSY' else 1


if __name__ == '__main__':
    raise SystemExit(main())
