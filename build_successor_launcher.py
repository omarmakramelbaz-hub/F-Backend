"""Rebuild the standalone successor from the exact reviewed original loader.

Only this repository build helper reads local source. The generated root
launcher authenticates every bundled helper before executing any of them.
"""
import ast
import base64
import hashlib
from pathlib import Path

SCRIPT_FOLDER = Path(__file__).resolve().parent
BASE = SCRIPT_FOLDER.parent if SCRIPT_FOLDER.name == 'deployment' else SCRIPT_FOLDER
ORIGINAL = BASE / 'deployment/run_go_erp_desktop_performance_root.py'
if not ORIGINAL.is_file():
    ORIGINAL = BASE.parent / 'perf_operator/deployment/run_go_erp_desktop_performance_root.py'
ORIGINAL_SHA = '13bca0a11b02df779ff7b5e66da4f109d388edc5399bc45259b7156ffb730366'
TARGET = BASE / 'deployment/run_go_erp_desktop_audit_successor_root.py'
CORE = '22f724b1ec75e8326e20305df40b91fe5640a7a7'
SEED = '6a9afe74203995a36416373528de5a29097fee83'
EXTRA_REVISIONS = (
    'b5503e21e3af32eecaf0d2f9d68eee7d21be7439',
    'd15942c947ac31c21a90b53856084a5de35d3cc3',
    '766f9d2d101fd639ffea8905e5b309f03eab8759',
    '6e39001095dbf5909c7b58cff69914c50da0f200',
    'f9c104bc0bfab1e8f3d0598b1800148f182c08bf',
)
EXTRA_HELPERS = {
    'successor_plan': 'go_erp_desktop_performance_successor.py',
    'successor_coordinator': 'upgrade_go_erp_desktop_audit_successor_root.py',
}


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def build():
    raw = ORIGINAL.read_bytes()
    if sha(raw) != ORIGINAL_SHA:
        raise RuntimeError('Reviewed original launcher bytes changed')
    source = raw.decode('utf-8')
    tree = ast.parse(source, feature_version=(3, 9))
    assignments = {}
    for node in tree.body:
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            assignments[node.targets[0].id] = (node, ast.literal_eval(node.value))
    old_roles = assignments['ROLES'][1]
    original_bundle = assignments['BUNDLED_MODULES'][1]
    if set(original_bundle) != set(old_roles):
        raise RuntimeError('Original bundle roster changed')
    bundle = dict(original_bundle)
    for role in old_roles:
        expected, encoded = original_bundle[role]
        helper = base64.b64decode(encoded, validate=True)
        if sha(helper) != expected:
            raise RuntimeError('Original helper hash changed: ' + role)
        ast.parse(helper, feature_version=(3, 9))
    for role, filename in EXTRA_HELPERS.items():
        helper = (BASE / 'deployment' / filename).read_bytes()
        ast.parse(helper, feature_version=(3, 9))
        bundle[role] = (sha(helper), base64.b64encode(helper).decode('ascii'))
    replacements = {
        'CORE': repr(CORE),
        'SEED': repr(SEED),
        'DEPENDENCY_REVISIONS': repr(tuple(dict.fromkeys((*assignments['DEPENDENCY_REVISIONS'][1], *EXTRA_REVISIONS)))),
        'ROLES': repr(tuple((*old_roles, *EXTRA_HELPERS))),
        'BUNDLED_MODULES': repr(bundle),
    }
    lines = source.splitlines(keepends=True)
    spans = []
    for name, literal in replacements.items():
        node = assignments[name][0]
        spans.append((node.lineno - 1, node.end_lineno, name + ' = ' + literal + '\n'))
    for first, last, replacement in sorted(spans, reverse=True):
        lines[first:last] = [replacement]
    source = ''.join(lines)
    old_call = """result = modules['coordinator'].run(package, operator, modules['current'],
            modules['payloads'], modules['source'], recovery={
                'plan': modules['recovery_plan'], 'evidence': modules['recovery_evidence'],
                'effects': modules['recovery_effects']})"""
    if source.count(old_call) != 1:
        raise RuntimeError('Original entrypoint call changed')
    source = source.replace(old_call, "result = modules['successor_coordinator'].run(package, operator, modules)")
    if source.count("'version': '0.4.4-preview.61'") != 1:
        raise RuntimeError('Original public version guard changed')
    source = source.replace("'version': '0.4.4-preview.61'", "'version': '0.4.4-preview.62'")
    source = source.replace('Pinned, self-contained launcher for the current desktop performance upgrade.',
                            'Pinned, self-contained launcher for the MIME-patched desktop successor.')
    ast.parse(source, feature_version=(3, 9))
    TARGET.write_text(source, encoding='utf-8')
    print('SUCCESSOR_LAUNCHER', TARGET.name, len(source.encode()), sha(source.encode()))
    for role in EXTRA_HELPERS:
        print('HELPER_PIN', role, bundle[role][0])


if __name__ == '__main__':
    build()
