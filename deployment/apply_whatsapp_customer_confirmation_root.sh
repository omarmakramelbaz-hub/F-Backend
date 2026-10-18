#!/usr/bin/env bash
set -euo pipefail
exec /usr/bin/python3 - "$@" <<'PY'
"""Quiesce existing pipelines for a two-file publication; install no units."""
import argparse
import hashlib
import importlib.util
import json
import os
import pathlib
import pwd
import re
import select
import signal
import stat
import subprocess
import sys
import tempfile
import time

ROOT = pathlib.Path('/home/fasakha/public_html')
PUBLISHER_BLOB = 'c85c4254ff04d5a05133169d90c92b9206ee71a1'
ROOT_LIBRARY_BLOB = '98ab88bba322c87c115f5ca95ff9aebbc96190f2'
HELPERS = {
    'apply_whatsapp_customer_confirmation.py': PUBLISHER_BLOB,
    'apply_meta_ai_control_root.sh': ROOT_LIBRARY_BLOB,
    'apply_meta_ai_control.py': '65cad14ba90b505be88649da73da045746429abc',
    'install_whatsapp_inbox.py': 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98',
}
SOURCE_ROSTER = ('app/Support/WhatsAppCustomerOrderConfirmation.php',
                 'app/Services/Dashboard/WhatsAppOrderWorkflow.php')
STATE_KEYS = {'sources', 'configuration_fingerprint', 'dependencies_fingerprint',
              'control_fingerprint', 'source_profile'}


class SafeError(Exception): pass


def blob(content):
    return hashlib.sha1(b'blob '+str(len(content)).encode()+b'\0'+content).hexdigest()


def command(args, timeout=60):
    try:
        result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=timeout)
        return (1, b'') if len(result.stdout) > 8388608 else (result.returncode, result.stdout)
    except (OSError, subprocess.TimeoutExpired):
        return 1, b''


def stage_helpers(directory, release, uid, gid, runner=command):
    for name, expected in HELPERS.items():
        if not re.fullmatch('[a-f0-9]{40}', expected):
            raise SafeError('WA_CONFIRMATION_REVIEWED_HELPER_PIN_REQUIRED')
        relative = 'deployment/'+name
        prefix = ['/sbin/runuser', '-u', 'fasakha', '--', 'git', '-C', str(ROOT)]
        code, tree = runner(prefix+['ls-tree', '-z', release, relative])
        if code or tree != b'100644 blob '+expected.encode()+b'\t'+relative.encode()+b'\0':
            raise SafeError('WA_CONFIRMATION_HELPER_TREE_MISMATCH')
        code, content = runner(prefix+['show', release+':'+relative])
        if code or not content or len(content) > 1048576 or blob(content) != expected:
            raise SafeError('WA_CONFIRMATION_HELPER_BLOB_MISMATCH')
        descriptor = os.open(directory/name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(content); stream.flush(); os.fchown(stream.fileno(), uid, gid); os.fsync(stream.fileno())


def checked_bytes(path, expected, uid):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(descriptor); content = os.read(descriptor, 1048577); after = os.fstat(descriptor)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid not in (0, uid) or before.st_nlink != 1
                or stat.S_IMODE(before.st_mode) != 0o600 or len(content) > 1048576 or blob(content) != expected
                or (before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns)
                != (after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)):
            raise SafeError('WA_CONFIRMATION_STAGED_HELPER_CHANGED')
        return content
    finally:
        os.close(descriptor)


def load_root_library(directory, uid):
    path = directory/'apply_meta_ai_control_root.sh'
    content = checked_bytes(path, ROOT_LIBRARY_BLOB, uid)
    try:
        source = content.decode().split("<<'PY'\n", 1)[1].rsplit('\nPY\n', 1)[0]
    except (ValueError, IndexError, UnicodeError):
        raise SafeError('WA_CONFIRMATION_ROOT_LIBRARY_INVALID') from None
    name = 'verified_customer_confirmation_root_library'
    module = importlib.util.module_from_spec(importlib.util.spec_from_loader(name, loader=None))
    module.__file__ = str(path); sys.modules[name] = module
    exec(compile(source, str(path), 'exec'), module.__dict__)
    return module


def call_publisher(directory, release, uid, gid, held_locks, action, expected=None):
    read_fd, write_fd = os.pipe(); pid = os.fork()
    if pid == 0:
        os.close(read_fd)
        try:
            def interrupted(signum, frame): raise InterruptedError()
            signal.signal(signal.SIGTERM, interrupted); signal.signal(signal.SIGINT, interrupted)
            os.setgroups([]); os.setgid(gid); os.setuid(uid); os.umask(0o077)
            path = directory/'apply_whatsapp_customer_confirmation.py'
            source = checked_bytes(path, PUBLISHER_BLOB, uid)
            name = 'verified_customer_confirmation_publisher'
            module = importlib.util.module_from_spec(importlib.util.spec_from_loader(name, loader=None))
            module.__file__ = str(path); sys.modules[name] = module
            exec(compile(source, str(path), 'exec'), module.__dict__)
            result = (module.verify_state(ROOT, uid, expected, held_locks)
                      if action == 'state' else module.apply(ROOT, uid, release,
                          action == 'preflight', expected, held_locks))
            response = {'ok': True, 'result': result}
        except BaseException as error:
            status = str(error) if (isinstance(error, SafeError) or
                'module' in locals() and isinstance(error, module.SafeError)) else 'WA_CONFIRMATION_CHILD_STOPPED'
            if not re.fullmatch(r'[A-Z_]+(?:; BACKUP=/home/fasakha/whatsapp-release-backups/whatsapp-customer-confirmation-[a-z0-9_]+)?', status):
                status = 'WA_CONFIRMATION_CHILD_STOPPED'
            response = {'ok': False, 'status': status}
        try:
            os.write(write_fd, json.dumps(response, separators=(',', ':')).encode())
        finally:
            os.close(write_fd); os._exit(0)
    os.close(write_fd); chunks = []; total = 0; waited = False; deadline = time.monotonic()+240
    try:
        while True:
            remaining = deadline-time.monotonic()
            if remaining <= 0: raise SafeError('WA_CONFIRMATION_CHILD_TIMEOUT')
            readable, _, _ = select.select([read_fd], [], [], min(1, remaining))
            if not readable: continue
            content = os.read(read_fd, 4096)
            if not content: break
            total += len(content)
            if total > 16384: raise SafeError('WA_CONFIRMATION_CHILD_OUTPUT_INVALID')
            chunks.append(content)
        _, code = os.waitpid(pid, 0); waited = True
        response = json.loads(b''.join(chunks))
        if code or not isinstance(response, dict) or type(response.get('ok')) is not bool:
            raise SafeError('WA_CONFIRMATION_CHILD_OUTPUT_INVALID')
        return response
    finally:
        os.close(read_fd)
        if not waited:
            try: os.kill(pid, signal.SIGTERM)
            except ProcessLookupError: pass
            until = time.monotonic()+10
            while time.monotonic() < until:
                try:
                    done, _ = os.waitpid(pid, os.WNOHANG)
                    if done: waited = True; break
                except ChildProcessError: waited = True; break
                time.sleep(0.05)
            if not waited:
                try: os.kill(pid, signal.SIGKILL)
                except ProcessLookupError: pass
                try: os.waitpid(pid, 0)
                except ChildProcessError: pass


def expected_state(value):
    if (not isinstance(value, dict) or not STATE_KEYS <= set(value)
            or not isinstance(value['sources'], dict) or tuple(value['sources']) != SOURCE_ROSTER
            or any(pin is not None and not re.fullmatch('[a-f0-9]{40}', pin) for pin in value['sources'].values())
            or any(not isinstance(value[key], str) or not re.fullmatch('[a-f0-9]{64}', value[key])
                   for key in ('configuration_fingerprint', 'dependencies_fingerprint', 'control_fingerprint'))
            or value['source_profile'] not in ('BASELINE', 'INSTALLED_V1', 'INSTALLED_V2', 'INSTALLED_V3', 'INSTALLED_V4', 'INSTALLED_V5', 'INSTALLED_V6', 'INSTALLED_V7', 'INSTALLED_V8', 'INSTALLED_V9', 'CURRENT')):
        raise SafeError('WA_CONFIRMATION_STATE_OUTPUT_INVALID')
    return {key: value[key] for key in STATE_KEYS}


def orchestrate(directory, release, uid, gid, held_locks, library, runner=command, child=call_publisher):
    response = child(directory, release, uid, gid, held_locks, 'preflight')
    if not response['ok']: raise SafeError(response['status'])
    value = response['result']; expected = expected_state(value)
    if value.get('status') != 'WA_CONFIRMATION_PREFLIGHT_READY' or type(value.get('changed')) is not bool:
        raise SafeError('WA_CONFIRMATION_PREFLIGHT_OUTPUT_INVALID')
    before = {name: library.unit_state(name, runner) for name in library.UNITS}
    profiles = {name: library.unit_profile(name) for name in library.UNITS}
    if any(state['LoadState'] != 'loaded' or profiles[name] is None for name, state in before.items()):
        raise SafeError('WA_CONFIRMATION_EXISTING_PIPELINES_REQUIRED')
    for index in (0, 2, 4, 6):
        timer = before[library.UNITS[index]]; service = before[library.UNITS[index+1]]
        if (timer['ActiveState'] not in ('active', 'inactive', 'failed')
                or service['ActiveState'] in ('active', 'activating') and timer['ActiveState'] != 'active'):
            raise SafeError('WA_CONFIRMATION_MANUAL_OR_TRANSITIONING_WORKER')
    parent = ROOT.parent/'whatsapp-release-backups'; library.owned_directory(parent, uid, True)
    receipt_path = pathlib.Path(tempfile.mkdtemp(prefix='whatsapp-customer-confirmation-pipeline-', dir=parent))
    receipt_path.chmod(0o700)
    library.write_receipt(receipt_path/'receipt.json', {'release': release, 'original_units': before,
        'unit_profiles': profiles, 'original_state': expected, 'publication_targets': list(SOURCE_ROSTER)})
    receipt = library.PipelineReceipt(receipt_path); receipt.mark('RECORDED')
    def guard():
        if any(library.unit_profile(name) != profiles[name] for name in library.UNITS):
            raise SafeError('WA_CONFIRMATION_UNIT_CHANGED')
        observed = child(directory, release, uid, gid, held_locks, 'state', expected)
        if (not observed['ok'] or observed['result'].get('status') != 'WA_CONFIRMATION_STATE_READY'
                or expected_state(observed['result']) != expected):
            raise SafeError('WA_CONFIRMATION_RESTART_STATE_NEEDS_REVIEW')
    paused = False; publication_attempted = False
    try:
        paused = True
        code, _ = runner([library.SYSTEMCTL, 'stop', *[name for name in library.UNITS if name.endswith('.timer')]])
        if code: raise SafeError('WA_CONFIRMATION_TIMERS_STOP_FAILED')
        receipt.mark('TIMERS_PAUSED'); library.wait_quiet(runner); receipt.mark('QUIESCENT'); guard()
        publication_attempted = True; receipt.mark('PUBLICATION_STARTED')
        response = child(directory, release, uid, gid, held_locks, 'apply', expected)
        if not response['ok']: raise SafeError(response['status'])
        value = response['result']; target = expected_state(value)
        if (value.get('status') not in ('WHATSAPP_CUSTOMER_CONFIRMATION_REPAIR_READY', 'WA_CONFIRMATION_ALREADY_CURRENT')
                or target['source_profile'] != 'CURRENT'
                or any(target[key] != expected[key] for key in ('configuration_fingerprint', 'dependencies_fingerprint', 'control_fingerprint'))):
            raise SafeError('WA_CONFIRMATION_PUBLICATION_OUTPUT_INVALID')
        expected = target; receipt.mark('PUBLISHED'); guard()
        library.restore_timers(before, profiles, runner, library.unit_profile, guard)
        paused = False; receipt.mark('RESTORED')
        return dict(value, pipeline_backup=str(receipt_path))
    except BaseException as error:
        if paused:
            if not publication_attempted:
                try:
                    library.restore_timers(before, profiles, runner, library.unit_profile, guard)
                    paused = False; receipt.mark('PAUSE_CANCELLED')
                except BaseException: pass
            if paused:
                # No capture or order process is restarted from an uncertain runtime.
                try: library.stop_all(runner); receipt.mark('RECOVERY_NEEDS_REVIEW')
                except BaseException: pass
                status = str(error) if isinstance(error, SafeError) else 'WA_CONFIRMATION_STOPPED'
                raise SafeError(status+'; PIPELINES_PAUSED; PIPELINE_BACKUP='+str(receipt_path)) from None
        raise


def main():
    parser = argparse.ArgumentParser(add_help=False); parser.add_argument('--release', required=True)
    arguments = parser.parse_args(); descriptors = []
    try:
        def interrupted(signum, frame): raise KeyboardInterrupt()
        signal.signal(signal.SIGTERM, interrupted); signal.signal(signal.SIGINT, interrupted)
        if os.geteuid() != 0 or not re.fullmatch('[a-f0-9]{40}', arguments.release):
            raise SafeError('WA_CONFIRMATION_ROOT_CONTEXT_INVALID')
        user = pwd.getpwnam('fasakha'); uid, gid = user.pw_uid, user.pw_gid
        with tempfile.TemporaryDirectory(prefix='.wa-customer-confirmation-helper-', dir=ROOT.parent) as folder:
            directory = pathlib.Path(folder); os.chown(directory, uid, gid); directory.chmod(0o700)
            stage_helpers(directory, arguments.release, uid, gid)
            library = load_root_library(directory, uid)
            library.owned_directory(ROOT, uid); library.owned_directory(ROOT/'.git', uid)
            library.owned_directory(ROOT.parent/'whatsapp-release-backups', uid, True)
            descriptors = library.acquire_locks(ROOT.parent/'whatsapp-release-backups', uid, gid)
            result = orchestrate(directory, arguments.release, uid, gid, descriptors, library)
        print(result['status'])
        if result.get('backup'): print('BACKUP='+result['backup'])
        print('PIPELINE_BACKUP='+result['pipeline_backup'])
        print('CONFIGURATION_AND_POLICY_UNCHANGED; EXISTING_PIPELINES_RESTORED; NO_REPLIES; NO_REANALYSIS; NO_ERP_WRITES')
    except BaseException as error:
        # Only fixed code/path messages from our verified helpers are emitted.
        value = str(error) if isinstance(error, SafeError) else 'WA_CONFIRMATION_ROOT_STOPPED_WITHOUT_SECRET_OUTPUT'
        print(value, file=sys.stderr); raise SystemExit(1)
    finally:
        for descriptor in descriptors: os.close(descriptor)


if __name__ == '__main__': main()
PY
