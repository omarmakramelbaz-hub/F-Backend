"""Reconstruct the exact published .56 native payload from immutable predecessor helpers."""
from pathlib import Path
import importlib.util
import subprocess
import types

from go_erp_direct_login_reference import REFERENCE
import test_go_erp_warm_start_repair_upgrade as older

ROOT = Path(__file__).resolve().parents[1]
RELEASE = 'deaa588337a84521eb7a6201066df3156991af64'
EXPECTED_COORDINATOR = 'd061a8527afc20185bbeb0971c9a5a85740219c6d99f3873eb44308328e3b643'
import hashlib
raw = subprocess.check_output(['git', 'show', RELEASE + ':deployment/upgrade_go_erp_direct_login_root.py'], cwd=ROOT)
if hashlib.sha256(raw).hexdigest() != EXPECTED_COORDINATOR:
    raise RuntimeError('LOGIN_REOPEN_REFERENCE_CHANGED')
previous = types.ModuleType('login_reopen_previous_operator')
previous.__file__ = '<pinned-previous-direct-login-operator>'
exec(compile(raw, previous.__file__, 'exec'), previous.__dict__)
template_raw = subprocess.check_output(['git', 'show', previous.TEMPLATE_RELEASE
    + ':deployment/upgrade_go_erp_warm_start_root.py'], cwd=ROOT)
template = previous.configure(previous.load_template(template_raw))
NEW = template.derive_native_payloads(older.NEW, older.tick.NEW_COHORT['pins'], previous.SEED)
tick_raw = subprocess.check_output(['git', 'show', RELEASE + ':deployment/go_erp_direct_login_tick_root.py'], cwd=ROOT)
tick = types.ModuleType('login_reopen_previous_tick')
tick.__file__ = '<pinned-previous-direct-login-tick>'
exec(compile(tick_raw, tick.__file__, 'exec'), tick.__dict__)
new_source = older.frozen('login_reopen_previous_source', NEW['go_erp_live_source.py'])
new_worker = older.frozen('login_reopen_previous_worker', NEW['go_erp_live_updates.py'])

def __getattr__(name):
    return getattr(older, name)
