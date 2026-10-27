"""Reconstruct immutable59 worker bytes without relying on a mutable deployment file."""
from pathlib import Path
import hashlib
import subprocess
import types

import go_erp_navigation_reference as older
from go_erp_navigation_reference import REFERENCE

ROOT = Path(__file__).resolve().parents[1]
RELEASE = '33e08c9fec93501d2376eb88d9fd402043f8d792'
EXPECTED_COORDINATOR = '49d065ed05a033c616101fdd9220de1d07260eb4b35285bb5b52d4a65bce6b81'
raw = subprocess.check_output(['git','show',RELEASE+':deployment/upgrade_go_erp_navigation_root.py'],cwd=ROOT)
if hashlib.sha256(raw).hexdigest() != EXPECTED_COORDINATOR:
    raise RuntimeError('OWNER_FAST_OPEN_REFERENCE_CHANGED')
previous=types.ModuleType('owner_fast_open_previous_operator');previous.__file__='<pinned-navigation59-operator>'
exec(compile(raw,previous.__file__,'exec'),previous.__dict__)
template_raw=subprocess.check_output(['git','show',previous.TEMPLATE_RELEASE+':deployment/upgrade_go_erp_warm_start_root.py'],cwd=ROOT)
template=previous.configure(previous.load_template(template_raw))
NEW=template.derive_native_payloads(older.NEW,older.tick.NEW_COHORT['pins'],previous.SEED)
tick_raw=subprocess.check_output(['git','show',RELEASE+':deployment/go_erp_navigation_tick_root.py'],cwd=ROOT)
tick=types.ModuleType('owner_fast_open_previous_tick');tick.__file__='<pinned-navigation59-tick>'
exec(compile(tick_raw,tick.__file__,'exec'),tick.__dict__)
new_source=older.frozen('owner_fast_open_previous_source',NEW['go_erp_live_source.py'])
new_worker=older.frozen('owner_fast_open_previous_worker',NEW['go_erp_live_updates.py'])


def __getattr__(name):
    return getattr(older,name)
