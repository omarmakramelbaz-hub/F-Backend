"""Reconstruct the exact published .57 native payload from immutable predecessor helpers."""
from pathlib import Path
import importlib.util
import subprocess
import types

from go_erp_login_reopen_reference import REFERENCE
import go_erp_login_reopen_reference as older

ROOT = Path(__file__).resolve().parents[1]
RELEASE = '419d2b9aefba7001f63cd9f999343d7c444f064a'
EXPECTED_COORDINATOR = '0d0dee7a27e1de2f491fcd444efeb04781cdf7c575c55e3a42a6d7c3c5eea641'
import hashlib
raw = subprocess.check_output(['git', 'show', RELEASE + ':deployment/upgrade_go_erp_login_reopen_root.py'], cwd=ROOT)
if hashlib.sha256(raw).hexdigest() != EXPECTED_COORDINATOR:
    raise RuntimeError('PASSWORD_LOGOUT_REFERENCE_CHANGED')
previous = types.ModuleType('password_logout_previous_operator')
previous.__file__ = '<pinned-previous-login-reopen-operator>'
exec(compile(raw, previous.__file__, 'exec'), previous.__dict__)
template_raw = subprocess.check_output(['git', 'show', previous.TEMPLATE_RELEASE
    + ':deployment/upgrade_go_erp_warm_start_root.py'], cwd=ROOT)
template = previous.configure(previous.load_template(template_raw))
NEW = template.derive_native_payloads(older.NEW, older.tick.NEW_COHORT['pins'], previous.SEED)
tick_raw = subprocess.check_output(['git', 'show', RELEASE + ':deployment/go_erp_login_reopen_tick_root.py'], cwd=ROOT)
tick = types.ModuleType('password_logout_previous_tick')
tick.__file__ = '<pinned-previous-login-reopen-tick>'
exec(compile(tick_raw, tick.__file__, 'exec'), tick.__dict__)
new_source = older.frozen('password_logout_previous_source', NEW['go_erp_live_source.py'])
new_worker = older.frozen('password_logout_previous_worker', NEW['go_erp_live_updates.py'])

def __getattr__(name):
    return getattr(older, name)
