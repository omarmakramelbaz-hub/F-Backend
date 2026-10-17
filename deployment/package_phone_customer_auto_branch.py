#!/usr/bin/env python3
"""Build a private, self-contained customer branch deployment ZIP from local Git objects."""
import argparse
import ast
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import zipfile

BASE_REVISION = 'c27278519f923a2fe351523ff3a149f14a38c04d'
UTILITY_REVISION = '6582750c2ef925500504a83d89141e49f0bd62a3'
UTILITY_PATH = 'deployment/apply_pos_inline_quantity.py'
UTILITY_BLOB = 'a438bfdcb17f43ef11605bcb48de926c190b3f73'
HELPER_PATH = 'deployment/apply_phone_customer_auto_branch.py'
SOURCES = ('app/Services/Dashboard/PosServicePhone.php', 'public/dashboard/js/phone-orders.js')
TOP = 'Fasakhansta-Customer-Auto-Branch-20261018'


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def digest(body):
    return hashlib.sha256(body).hexdigest()


def git(root, *arguments, input=None):
    value = subprocess.run(['git', '-C', str(root), *arguments], input=input,
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)
    require(value.returncode == 0, 'LOCAL_GIT_OBJECT_REQUIRED')
    return value.stdout


def function_replace(source, name, replacement):
    node = next(node for node in ast.parse(source).body if isinstance(node, ast.FunctionDef) and node.name == name)
    lines = source.splitlines(keepends=True)
    lines[node.lineno - 1:node.end_lineno] = [replacement.rstrip() + '\n']
    return ''.join(lines)


def adapt_helper(source, revision, hashes):
    utility_loader = '''def verified_payload(relative):
    expected = BUNDLE_PAYLOAD_SHA256.get(relative)
    if expected is None:
        raise RuntimeError('BUNDLE_PAYLOAD_UNDECLARED')
    root = Path(__file__).resolve().parent
    target = root / relative
    for part in (target, *target.parents):
        if part.is_symlink():
            raise RuntimeError('BUNDLE_SYMLINK_REJECTED')
    if not target.is_file():
        raise RuntimeError('BUNDLE_PAYLOAD_MISSING')
    body = target.read_bytes()
    if len(body) > 8388608 or hashlib.sha256(body).hexdigest() != expected:
        raise RuntimeError('BUNDLE_PAYLOAD_HASH_MISMATCH')
    return body


def load_common(root):
    """Load only the utility bytes embedded and SHA256-verified in this archive."""
    source = verified_payload('utility.py')
    with tempfile.TemporaryDirectory(prefix='phone-customer-branch-utility-') as temporary:
        helper = Path(temporary) / 'utility.py'
        helper.write_bytes(source)
        spec = importlib.util.spec_from_file_location('bundled_phone_customer_branch_utility', helper)
        common = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(common)
    return common'''
    source = function_replace(source, 'load_common', utility_loader)
    source = function_replace(source, 'build_plan', '''def build_plan(c, root, revision):
    c.require(revision == BUNDLE_REVISION, 'BUNDLE_REVISION_MISMATCH')
    plan = {}
    for index, relative in enumerate(SOURCE_MANIFEST):
        patch = verified_payload('patches/' + str(index) + '.diff')
        c.require(patch.startswith(('diff --git a/' + relative + ' b/' + relative + '\\n').encode()),
                  'BUNDLE_PATCH_TARGET_INVALID')
        plan[relative] = {'status': 'M', 'patch': patch}
    return plan''')
    source = function_replace(source, 'install', '''def install(root, php, check_only=False):
    c = load_common(root)
    c.require(root.is_absolute() and root.resolve() == root and root.is_dir(), 'APPLICATION_ROOT_INVALID')
    c.safe(root)
    plan = build_plan(c, root, BUNDLE_REVISION)
    previous_umask = os.umask(0o022)
    try:
        return apply_update(c, root, BUNDLE_REVISION, php, plan, check_only)
    finally:
        os.umask(previous_umask)''')
    source = function_replace(source, 'main', '''def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default='/home/fasakha/public_html')
    parser.add_argument('--php', default='/opt/cpanel/ea-php82/root/usr/bin/php')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    if os.geteuid() != pwd.getpwnam('fasakha').pw_uid:
        raise RuntimeError('RUN_AS_FASAKHA_REQUIRED')
    install(Path(args.root), args.php, check_only=args.check)''')
    validation = """    resolved = c.git(root, 'rev-parse', '--verify', revision + '^{commit}')
    c.require(resolved.returncode == 0 and resolved.stdout.decode().strip() == revision,
            'RELEASE_COMMIT_UNAVAILABLE')
    c.require(c.git(root, 'merge-base', '--is-ancestor', BASE_REVISION, revision).returncode == 0,
            'RELEASE_BASE_MISMATCH')"""
    require(source.count(validation) == 1, 'HELPER_COMMIT_VALIDATION_CHANGED')
    source = source.replace(validation, "    c.require(revision == BUNDLE_REVISION, 'BUNDLE_REVISION_MISMATCH')")
    receipt = "receipt = {'revision': revision, 'base_revision': BASE_REVISION,"
    require(source.count(receipt) == 1, 'HELPER_RECEIPT_CHANGED')
    source = source.replace(receipt, "receipt = {'revision': revision, 'base_revision': BASE_REVISION,\n                       'delivery_mode': 'verified_local_bundle',\n                       'payload_sha256': BUNDLE_PAYLOAD_SHA256,")
    source = source.replace('import argparse\n', 'import argparse\nimport hashlib\n', 1)
    constants = 'BUNDLE_REVISION = ' + repr(revision) + '\nBUNDLE_PAYLOAD_SHA256 = ' + repr(hashes) + '\n\n'
    source = source.replace('BASE_REVISION = ', constants + 'BASE_REVISION = ', 1)
    source = source.replace('Apply delivery customer branch selection without replaying other deployments.',
                            'Apply the SHA256-verified local customer branch bundle; no remote Git access.')
    require('RELEASE_COMMIT_UNAVAILABLE' not in source and 'RELEASE_BASE_MISMATCH' not in source,
            'BUNDLE_CONTAINS_REMOTE_COMMIT_PREREQUISITE')
    compile(source, 'install.py', 'exec')
    return source.encode()


def launcher(hashes):
    files = sorted(hashes)
    checks = '\n'.join(hashes[name] + '  ' + name for name in files)
    copy = '\n'.join('install -m 0644 -- "$bundle_source/' + name + '" "$bundle_stage/' + name + '"' for name in files)
    return ('''#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ $(id -u) = 0 ]] || { printf '%s\\n' 'Run INSTALL.sh as root.'; exit 1; }
[[ $# -le 1 ]] || exit 1
bundle_options=()
if [[ $# -eq 1 ]]; then
  [[ $1 = --check ]] || exit 1
  bundle_options=(--check)
fi
bundle_source=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
bundle_php=/opt/cpanel/ea-php82/root/usr/bin/php
[[ -x "$bundle_php" ]] || { printf '%s\\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
command -v git >/dev/null
command -v sha256sum >/dev/null
cd -- "$bundle_source"
'''+ ''.join('[[ -f "'+name+'" && ! -L "'+name+'" ]] || { printf \'%s\\n\' \'BUNDLE_FILE_INVALID\'; exit 1; }\n' for name in files)
        + "[[ -d patches && ! -L patches ]] || exit 1\nsha256sum --strict --check <<'BUNDLE_SHA256'\n" + checks + "\nBUNDLE_SHA256\n"
        + '''bundle_stage=$(mktemp -d /tmp/fasakhansta-customer-branch.XXXXXXXX)
trap 'rm -rf -- "$bundle_stage"' EXIT
chmod 0755 "$bundle_stage"
install -d -m 0755 -- "$bundle_stage/patches"
''' + copy + '''
# Check the root-owned staged copies again before allowing the app user to read them.
cd -- "$bundle_stage"
sha256sum --strict --check <<'BUNDLE_SHA256'
''' + checks + '''
BUNDLE_SHA256
runuser -u fasakha -- python3 "$bundle_stage/install.py" \\
  --root /home/fasakha/public_html --php "$bundle_php" "${bundle_options[@]}"
''').encode()


def build_archive(root, revision, output):
    root = root.resolve()
    require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    require(git(root, 'rev-parse', '--verify', revision + '^{commit}').decode().strip() == revision,
            'LOCAL_RELEASE_COMMIT_MISMATCH')
    git(root, 'merge-base', '--is-ancestor', BASE_REVISION, revision)
    utility = git(root, 'show', UTILITY_REVISION + ':' + UTILITY_PATH)
    require(git(root, 'hash-object', '--no-filters', '--stdin', input=utility).decode().strip() == UTILITY_BLOB,
            'UTILITY_BLOB_MISMATCH')
    payloads = {'utility.py': utility}
    for index, relative in enumerate(SOURCES):
        patch = git(root, 'diff', '--no-ext-diff', '--no-textconv', '--no-renames', '--full-index', '--unified=3',
                    BASE_REVISION, revision, '--', relative)
        require(patch.startswith(('diff --git a/' + relative + ' b/' + relative + '\n').encode())
                and b'GIT binary patch' not in patch and b'old mode ' not in patch and b'new file mode ' not in patch
                and len(patch) <= 8388608, 'NARROW_SOURCE_PATCH_REQUIRED')
        payloads['patches/' + str(index) + '.diff'] = patch
    hashes = {name: digest(body) for name, body in payloads.items()}
    helper = git(root, 'show', revision + ':' + HELPER_PATH).decode()
    payloads['install.py'] = adapt_helper(helper, revision, hashes)
    hashes['install.py'] = digest(payloads['install.py'])
    manifest = {'format': 1, 'delivery_mode': 'verified_local_bundle', 'revision': revision,
                'base_revision': BASE_REVISION, 'source_files': list(SOURCES), 'sha256': hashes}
    payloads['manifest.json'] = (json.dumps(manifest, indent=2) + '\n').encode()
    hashes['manifest.json'] = digest(payloads['manifest.json'])
    payloads['INSTALL.sh'] = launcher(hashes)
    payloads['README.txt'] = ('''Fasakhansta delivery customer branch selection\n\nUpload this ZIP to /home/fasakha (outside public_html), extract it, and run INSTALL.sh as root.
Using /root is also supported.\nThe launcher verifies the exact bundled installer and payload hashes, copies only those files\nto a root-owned temporary location readable by fasakha, then executes as fasakha.\nNo Git fetch, checkout, reset, remote publication, database update, migration or cache clearing runs.\nOnly the two listed source files are patched. Compatible local edits and existing timing changes\nare preserved; conflicting changes stop before publication. Backups and rollback behavior remain.\nAdd --check to INSTALL.sh to perform read-only source preflight without publication or receipts.\nSuccess: PHONE_CUSTOMER_AUTO_BRANCH_OK. Repeating the update is safe.\nThe revision identifies the local source used to build the archive; the server does not need\nthat Git commit. Verify the outer ZIP SHA256 against the supplied value; embedded SHA256 checks verify bundled byte integrity.\nBackups: /home/fasakha/phone-customer-auto-branch-backups/\n\nSource revision: ''' + revision + '\nSource baseline: ' + BASE_REVISION + '\n').encode()
    output = output.absolute()
    require(not output.exists(), 'OUTPUT_ALREADY_EXISTS')
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, body in payloads.items():
            path = PurePosixPath(name)
            require(not path.is_absolute() and all(part not in ('..', '') for part in path.parts), 'BUNDLE_PATH_INVALID')
            info = zipfile.ZipInfo(TOP + '/' + name, date_time=(2026, 10, 18, 0, 0, 0))
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | (0o755 if name == 'INSTALL.sh' else 0o644)) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, body)
    return digest(output.read_bytes())


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--revision', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    print(build_archive(args.root, args.revision, args.output) + '  ' + str(args.output))


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, subprocess.SubprocessError, ValueError) as error:
        print('STOPPED: ' + str(error))
        raise SystemExit(1)
