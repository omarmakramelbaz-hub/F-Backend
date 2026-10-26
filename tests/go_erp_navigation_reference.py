"""Reconstruct the exact published .58 native payload from immutable predecessor helpers."""
from pathlib import Path
import importlib.util
import subprocess
import types

from go_erp_password_logout_reference import REFERENCE
import go_erp_password_logout_reference as older

ROOT = Path(__file__).resolve().parents[1]
RELEASE = '56c8680ac8d3d5cb68eaebd623daeba897cc3a48'
EXPECTED_COORDINATOR = 'c18a97f131615436acef59e291f3d244a978585a47411ce77c849c8433b11d69'
import hashlib
raw = subprocess.check_output(['git', 'show', RELEASE + ':deployment/upgrade_go_erp_password_logout_root.py'], cwd=ROOT)
if hashlib.sha256(raw).hexdigest() != EXPECTED_COORDINATOR:
    raise RuntimeError('NAVIGATION_REFERENCE_CHANGED')
previous = types.ModuleType('navigation_previous_operator')
previous.__file__ = '<pinned-previous-login-reopen-operator>'
exec(compile(raw, previous.__file__, 'exec'), previous.__dict__)
template_raw = subprocess.check_output(['git', 'show', previous.TEMPLATE_RELEASE
    + ':deployment/upgrade_go_erp_warm_start_root.py'], cwd=ROOT)
template = previous.configure(previous.load_template(template_raw))
NEW = template.derive_native_payloads(older.NEW, older.tick.NEW_COHORT['pins'], previous.SEED)
tick_raw = subprocess.check_output(['git', 'show', RELEASE + ':deployment/go_erp_password_logout_tick_root.py'], cwd=ROOT)
tick = types.ModuleType('navigation_previous_tick')
tick.__file__ = '<pinned-previous-login-reopen-tick>'
exec(compile(tick_raw, tick.__file__, 'exec'), tick.__dict__)
new_source = older.frozen('navigation_previous_source', NEW['go_erp_live_source.py'])
new_worker = older.frozen('navigation_previous_worker', NEW['go_erp_live_updates.py'])

def __getattr__(name):
    return getattr(older, name)
