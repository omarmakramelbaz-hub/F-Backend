#!/usr/bin/env python3
"""Site-specific HTTPS witness adapter for the exact reviewed preview-60 resume.

Keep the original PHP handler, release pins, checkpoints and complete HTTPS
challenge validation. Place the witness in the site's served public directory,
connect to its locally attested vhost IP, and journal the adapter separately.
"""
import ast
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import textwrap
import types

COMPAT_SHA = '224c5a123edad1b17eccaed671a3a4e8cb0f418b0ef49cf600334245317fd389'
ACTIVATION_SHA = 'ce2894a4369d0896de323ad99d92e5bad2c48c3d986053586b16d13d0f4b013f'
ADDRESS = '187.127.92.124'
USERDATA = Path('/var/cpanel/userdata/fasakha/fasakhaninja.com')


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_SITE_HTTPS_' + code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def local_address(engine):
    raw = engine.read_file(USERDATA, 2 * 1024**2, uid=0,
                           modes=(0o600, 0o640, 0o644))[0]
    values = re.findall(rb'(?m)^ip:[ \t]*[\"\']?([0-9.]+)[\"\']?[ \t]*$', raw)
    need(values == [ADDRESS.encode()], 'CPANEL_ADDRESS_CHANGED')
    executable = shutil.which('ip', path='/usr/sbin:/usr/bin:/sbin:/bin')
    need(executable is not None, 'LOCAL_ADDRESS_TOOL_REQUIRED')
    result = subprocess.run([executable, '-j', '-4', 'address', 'show'],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
        timeout=30, check=True, env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL': 'C'})
    need(len(result.stdout) <= 2 * 1024**2, 'LOCAL_ADDRESS_LIMIT')
    rows = json.loads(result.stdout)
    need(any(a.get('local') == ADDRESS for row in rows
             for a in row.get('addr_info', [])), 'VHOST_ADDRESS_NOT_LOCAL')
    return ADDRESS


def method_source(raw, class_name, method_name):
    tree = ast.parse(raw)
    classes = [n for n in tree.body if isinstance(n, ast.ClassDef) and n.name == class_name]
    need(len(classes) == 1, 'ORIGINAL_CLASS_CHANGED')
    methods = [n for n in classes[0].body
               if isinstance(n, ast.FunctionDef) and n.name == method_name]
    need(len(methods) == 1, 'ORIGINAL_METHOD_CHANGED')
    node = methods[0]
    return textwrap.dedent(''.join(raw.decode().splitlines(keepends=True)
                                   [node.lineno - 1:node.end_lineno])), node


def compile_method(source, namespace):
    scope = dict(namespace)
    exec(compile(source, '<site-https-adapter>', 'exec'), scope)
    functions = [value for name, value in scope.items()
                 if name not in namespace and isinstance(value, types.FunctionType)]
    need(len(functions) == 1, 'ADAPTER_METHOD_INVALID')
    return functions[0]


def retire_stale_probe(actor, module, action, folder, program_code):
    """Retire ownership only: never remove a probe file, pending action or receipt."""
    owned_path = folder / 'probe.json'
    original = actor.io.read(owned_path, 0, 0, optional=True, private=True)
    if original is None:
        return
    owned = actor._load(owned_path)
    pending = actor._load(folder / 'pending.json')
    need(actor._load(folder / 'complete.json') is None, 'COMPLETED_ACTION_IMMUTABLE')
    need(isinstance(pending, dict) and pending.get('action_sha256') == module.digest(action)
         and re.fullmatch(r'go-erp-readiness-[a-f0-9]{32}\.php', pending.get('name', ''))
         and all(re.fullmatch(r'[a-f0-9]{64}', pending.get(k, '')) for k in ('token', 'nonce')),
         'STALE_PROBE_INTENT_CHANGED')
    scope = {'ROOT': module.ROOT, 'sha': module.sha, 'pending': pending}
    exec(program_code, scope)
    need(isinstance(owned, dict) and set(owned) == {'inode', 'sha256'}
         and owned['sha256'] == module.sha(scope['program'])
         and isinstance(owned['inode'], list) and len(owned['inode']) == 2
         and all(type(x) is int for x in owned['inode']), 'STALE_PROBE_OWNERSHIP_CHANGED')
    for parent in (module.ROOT, module.ROOT / 'public'):
        need(not os.path.lexists(parent / pending['name']), 'EXISTING_PROBE_REVIEW_REQUIRED')
    need(actor.verify_locks() is not False, 'ORIGINAL_LOCKS_REQUIRED')
    # Preserve a root-private copy before retiring this one stale ownership file.
    actor._save(folder / ('site-retired-probe-' + module.sha(original[0]) + '.json'), owned)
    parent = actor.io.parent_fd(owned_path)
    try:
        now = os.stat(owned_path.name, dir_fd=parent, follow_symlinks=False)
        need(actor.io.identity(now) == actor.io.identity(original[1]), 'STALE_RECORD_CHANGED')
        need(actor.verify_locks() is not False, 'ORIGINAL_LOCKS_REQUIRED')
        os.unlink(owned_path.name, dir_fd=parent)
        os.fsync(parent)
    finally:
        os.close(parent)
    print('GO_ERP_SITE_HTTPS_STALE_PROBE_RECORD_RETIRED', flush=True)


def install_adapter(operator, raw, adapter_sha, address):
    need(sha(raw) == ACTIVATION_SHA and address == ADDRESS, 'ORIGINAL_ACTIVATION_CHANGED')
    module = operator.modules['activation']
    https_source, _ = method_source(raw, 'HTTPSClient', 'get')
    old_target = "socket.create_connection(('127.0.0.1', 443), timeout=15)"
    need(https_source.count(old_target) == 1, 'ORIGINAL_TCP_TARGET_CHANGED')
    adapted_get = compile_method(https_source.replace(old_target,
        'socket.create_connection((_GO_ERP_SITE_IP, 443), timeout=15)'),
        {**vars(module), '_GO_ERP_SITE_IP': address})
    witness_source, node = method_source(raw, 'Activation', '_https')
    old_path = "path = documentroot / pending['name']"
    need(witness_source.count(old_path) == 1, 'ORIGINAL_PROBE_PATH_CHANGED')
    adapted_https = compile_method(witness_source.replace(old_path,
        "path = (ROOT / 'public' if documentroot == ROOT else documentroot) / pending['name']"), vars(module))
    assignment = [n for n in node.body if isinstance(n, ast.Assign)
                  and any(isinstance(t, ast.Name) and t.id == 'program' for t in n.targets)]
    need(len(assignment) == 1, 'ORIGINAL_PROBE_PROGRAM_CHANGED')
    program_code = compile(ast.Module(body=assignment, type_ignores=[]), '<original-probe-program>', 'exec')

    def routing(actor):
        actor.io.directory(module.ROOT / 'public', module.APP_UID, module.APP_GID)
        result = {}
        for name in ('.htaccess', 'public/.htaccess'):
            value, info = actor._read(module.ROOT / name)
            result[name] = {'sha256': module.sha(value), 'inode': actor.io.inode(info)}
        return result

    def provenance(actor, action):
        need(actor.verify_locks() is not False, 'ORIGINAL_LOCKS_REQUIRED')
        handler = actor._handler()  # Original verifier and saved configuration hash.
        root = handler['configuration']['documentroot']
        need(root in (str(module.ROOT), str(module.ROOT / 'public')), 'DOCUMENTROOT_CHANGED')
        before = routing(actor)
        return {'format': 1, 'kind': 'go-erp-site-https-witness-adapter',
            'adapter_sha256': adapter_sha, 'original_activation_sha256': ACTIVATION_SHA,
            'original_handler_configuration_sha256': module.digest(handler['configuration']),
            'original_action_sha256': module.digest(action), 'connect_address': address,
            'sni': 'fasakhaninja.com', 'documentroot': root,
            'probe_directory': str(module.ROOT / 'public'), 'routing': before}

    original_perform = module.Activation._perform

    def site_perform(actor, action, recovering):
        # The original engine replays a completed receipt without calling _https.
        # Apply the supplemental routing binding before that replay as well.
        if action.get('name') in ('https_readiness', 'activation_https'):
            actor._approved(action)
            folder = actor._folder(action)
            if actor._load(folder / 'complete.json') is not None:
                need(actor._load(folder / 'site-https-adapter.json') == provenance(actor, action),
                     'COMPLETED_HTTPS_ADAPTER_BINDING_CHANGED')
        return original_perform(actor, action, recovering)

    def site_https(actor, action, folder, recovering):
        record = provenance(actor, action)
        before = record['routing']
        actor._save(folder / 'site-https-adapter.json', record)
        retire_stale_probe(actor, module, action, folder, program_code)
        finish = actor._finish
        prior_override = actor.__dict__.get('_finish')

        def checked_finish(*args, **kwargs):
            need(routing(actor) == before, 'ROUTING_CHANGED_DURING_HTTPS')
            need(actor.verify_locks() is not False, 'ORIGINAL_LOCKS_REQUIRED')
            return finish(*args, **kwargs)

        actor._finish = checked_finish
        try:
            return adapted_https(actor, action, folder, recovering)
        finally:
            if prior_override is None:
                del actor._finish
            else:
                actor._finish = prior_override

    module.HTTPSClient.get = adapted_get
    module.Activation._https = site_https
    module.Activation._perform = site_perform
    return operator


def main():
    need(os.geteuid() == 0, 'ROOT_REQUIRED')
    need(len(sys.argv) >= 2, 'PINNED_COMPAT_PATH_REQUIRED')
    path = Path(sys.argv[1])
    info = path.lstat()
    need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o077
         and info.st_nlink == 1 and path.resolve() == path, 'COMPAT_FILE_UNSAFE')
    raw = path.read_bytes()
    need(sha(raw) == COMPAT_SHA, 'PINNED_COMPAT_CHANGED')
    adapter_sha = sha(Path(__file__).read_bytes())
    compat = types.ModuleType('site_https_original_compat')
    compat.__file__ = str(path)
    sys.modules[compat.__name__] = compat
    exec(compile(raw, str(path), 'exec'), compat.__dict__)
    original_build = compat.build_operator

    def build(base, package, acceptance):
        operator = original_build(base, package, acceptance)
        address = local_address(operator.engine)
        source = package.file('deployment/go_erp_shared_workspace_activation_root.py')
        return install_adapter(operator, source, adapter_sha, address)

    compat.build_operator = build
    sys.argv = [str(path), *sys.argv[2:]]
    compat.main()


if __name__ == '__main__':
    try:
        main()
    except BaseException as error:
        code = str(error)
        print(code if re.fullmatch(r'GO_ERP_[A-Z0-9_]{1,160}', code)
              else 'GO_ERP_SITE_HTTPS_PRIVATE_FAILURE', flush=True)
        raise SystemExit(1)
