#!/usr/bin/env python3
"""Read-only diagnosis of the exact retained 2d9e payment/navigation package.

No execute(), transaction(), candidate fetch, service change, or database call.
Only a pre-existing lock is opened read-only; every inspected module is pinned.
"""
import ast
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import sys
import types

PACKAGE = Path('/var/lib/go-erp-live-updates/program-download.PLfU5USt/package')
PINS = {
    'upgrade_go_erp_payment_nav_successor_root.py': ('68fd6bc1f3b551bf5fa0bfd613c1f5f0dd070094', 'GO_ERP_NAV_SUCCESSOR_'),
    'upgrade_go_erp_program_watcher_root.py': ('602ba86d2fd59221e046b58fa9c197fa4544973e', ''),
    'upgrade_go_erp_preview31_watcher_root.py': ('ede9af30ea42ba53a5376d5e1cf634d9b3032f2a', ''),
    'upgrade_go_erp_capacity_watcher_root.py': ('60df7ec876f9634e0a7dcb4c5a420d0d5ecd9ae9', ''),
    'go_erp_payment_nav_application_patch.py': ('a660dc3c3a1d597ca86297e0a61eee1dd803c18f', 'GO_ERP_PROGRAM_APPLICATION_'),
    'go_erp_program_rollout_gate.py': ('f24125324966d271158d6fe96b5472ae6e0ef894', 'GO_ERP_PROGRAM_GATE_'),
    'refresh_desktop_dashboard_server_manifest.py': ('8a6834d46dcc29dc4af05fab7d7d47aac6bd5242', ''),
}
MAX_FILE = 2 * 1024 * 1024


class DiagnosticStop(Exception):
    pass


def need(value):
    if not value:
        raise DiagnosticStop()


def identity(item):
    return (item.st_dev, item.st_ino, item.st_mode, item.st_uid, item.st_gid,
            item.st_nlink, item.st_size, item.st_mtime_ns, item.st_ctime_ns)


def read_pinned(path, wanted):
    for parent in (path.parent, *path.parent.parents):
        item = parent.lstat()
        need(stat.S_ISDIR(item.st_mode) and item.st_uid == 0
             and not stat.S_ISLNK(item.st_mode) and not item.st_mode & 0o022)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        need(stat.S_ISREG(before.st_mode) and
             (before.st_uid, before.st_gid, before.st_nlink, stat.S_IMODE(before.st_mode)) == (0, 0, 1, 0o644)
             and 0 < before.st_size <= MAX_FILE)
        data = bytearray()
        while len(data) <= MAX_FILE:
            part = os.read(fd, min(65536, MAX_FILE + 1 - len(data)))
            if not part:
                break
            data.extend(part)
        need(len(data) == before.st_size and identity(before) == identity(os.fstat(fd)) == identity(path.lstat()))
        actual = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
        need(actual == wanted)
        return bytes(data)
    finally:
        os.close(fd)


def literal_reasons(data, prefix):
    """Only literal reason arguments in this exact pinned source are public."""
    result = set()
    for node in ast.walk(ast.parse(data)):
        if not isinstance(node, ast.Call):
            continue
        name = node.func.id if isinstance(node.func, ast.Name) else None
        argument = None
        if name in ('need', 'program_gate_need') and len(node.args) >= 2:
            argument = node.args[1]
            head = prefix
        elif name in ('Stop', 'ProgramGateError') and node.args:
            argument = node.args[0]
            head = ''
        if argument is not None:
            constant = getattr(ast, 'Constant', ())
            value = argument.value if isinstance(argument, constant) else None
            if value is None and sys.version_info < (3, 8) and isinstance(argument, ast.Str):
                value = argument.s
            if type(value) is str:
                result.add(head + value)
    return frozenset(value for value in result if re.fullmatch(r'[A-Z][A-Z0-9_]{0,127}', value))


class Reporter:
    def __init__(self):
        self.trusted = {}
        self.rows = []

    def register(self, path, data, prefix):
        def definitions(code):
            result = {code}
            for item in code.co_consts:
                if isinstance(item, types.CodeType):
                    result.update(definitions(item))
            return result
        self.trusted[str(path)] = (path.name, literal_reasons(data, prefix),
                                   definitions(compile(data, str(path), 'exec')))

    def failure(self, error):
        trace = error.__traceback__
        while trace is not None and trace.tb_next is not None:
            trace = trace.tb_next
        result = {'error_class': 'ExternalError', 'reason': 'PRIVATE_FAILURE'}
        if type(error) in (FileNotFoundError, PermissionError, OSError, ValueError, TypeError,
                           AttributeError, KeyError, RuntimeError, TimeoutError,
                           UnicodeDecodeError, json.JSONDecodeError):
            result['error_class'] = type(error).__name__
        if trace is not None:
            frame = trace.tb_frame
            path = frame.f_code.co_filename
            defining_module = sys.modules.get(frame.f_globals.get('__name__'))
            trusted = self.trusted.get(path)
            if not (trusted is not None and frame.f_globals.get('__file__') == path
                    and defining_module is not None and defining_module.__dict__ is frame.f_globals
                    and frame.f_code in trusted[2]):
                return result
            name, allowed, _ = trusted
            result.update(file=name, line=trace.tb_lineno)
            trusted_class = frame.f_globals.get('Stop')
            gate_class = frame.f_globals.get('ProgramGateError')
            if type(error) in (trusted_class, gate_class):
                result['error_class'] = 'ProgramGateError' if type(error) is gate_class else 'Stop'
                arguments = error.args
                if len(arguments) == 1 and type(arguments[0]) is str and arguments[0] in allowed:
                    result['reason'] = arguments[0]
        return result

    def step(self, name, action):
        try:
            value = action()
        except BaseException as error:
            self.rows.append({'step': name, 'ok': False, **self.failure(error)})
            return False, None
        self.rows.append({'step': name, 'ok': True})
        return True, value


def module(path, data):
    value = types.ModuleType('readonly_payment_navigation_' + path.stem)
    value.__file__ = str(path)
    sys.modules[value.__name__] = value
    exec(compile(data, str(path), 'exec'), value.__dict__)
    return value


def readonly_candidate(ctx):
    """The initial candidate proof uses existing Git objects only; never fetch."""
    entry, prior, base = ctx.entry, ctx.prior, ctx.base
    revision = entry.OLD_CANDIDATE
    header = base.git(entry.WORK / 'checkout', 'cat-file', 'commit', revision).split(b'\n\n', 1)[0]
    entry.need([row[7:].decode() for row in header.splitlines() if row.startswith(b'parent ')] == [entry.SEED],
               'CANDIDATE_PARENT_INVALID')
    names = set(base.git(entry.WORK / 'checkout', 'diff', '--name-only', '--no-renames', '-z',
                         entry.SEED, revision, '--').decode().split('\0')) - {''}
    entry.need(names.issubset(set(prior.VERSIONS)), 'CANDIDATE_CODE_INVALID')
    metadata = base.parse(base.git(entry.WORK / 'checkout', 'show', revision + ':' + prior.VERSIONS[2]))
    rows = metadata.get('inventory')
    entry.need(isinstance(rows, dict) and metadata == prior.candidate_metadata(entry.SEED, rows, 36)
               and metadata['inventorySha256'] == entry.OLD_INVENTORY, 'CANDIDATE_METADATA_INVALID')
    for path in prior.VERSIONS[:2]:
        before = base.parse(base.git(entry.WORK / 'checkout', 'show', entry.SEED + ':' + path))
        after = base.parse(base.git(entry.WORK / 'checkout', 'show', revision + ':' + path))
        before['version'] = entry.OLD_VERSION
        if path.endswith('package-lock.json'):
            before['packages']['']['version'] = entry.OLD_VERSION
        entry.need(after == before, 'CANDIDATE_PACKAGE_INVALID')


def run(package=PACKAGE):
    report = Reporter()
    result = {'format': 1, 'kind': 'go-erp-payment-navigation-readonly-diagnostic',
              'application_writes': False, 'database_accessed': False, 'service_changed': False,
              'candidate_fetch_performed': False, 'remote_lease_checked': False, 'credentials_disclosed': False}
    ok, _ = report.step('host', lambda: need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524'))
    if not ok:
        return {**result, 'checks': report.rows}
    code = {}
    for name, (pin, prefix) in PINS.items():
        ok, data = report.step('pin:' + name, lambda name=name, pin=pin: read_pinned(package / name, pin))
        if not ok:
            return {**result, 'checks': report.rows}
        code[name] = data
        report.register(package / name, data, prefix)
    entry = module(package / 'upgrade_go_erp_payment_nav_successor_root.py', code['upgrade_go_erp_payment_nav_successor_root.py'])
    # Preserve partial Context only for diagnosis; no partially initialized
    # context is ever used for controls, services, or a mutation.
    ctx = object.__new__(entry.Context)
    context_ok, _ = report.step('context', lambda: entry.Context.__init__(ctx, package))
    if context_ok:
        ctx.entry = entry
        quiescent, _ = report.step('quiescent', ctx.prior.assert_quiescent)
        if quiescent:
            def inspect_controls():
                with ctx.prior.held_lock(ctx.utility, entry.WORK) as (_, verify):
                    observations, state = ctx.controls()
                    verify()
                    return state
            controls_ok, state = report.step('controls', inspect_controls)
            report.step('pending-transactions', lambda: ctx.prior.require_no_pending(ctx.base, ctx.utility, entry.WORK))
            hold_ok, hold = report.step('hold-read', lambda: ctx.gate.program_gate_json(entry.WORK / 'program-rollout-hold.json'))
            if hold_ok:
                result['hold_original_matches'] = hold == entry.hold_value(ctx.old_expected)
                result['hold_successor_matches'] = hold == entry.hold_value(ctx.expected)
                result['hold_authorization_present'] = isinstance(hold, dict) and hold.get('authorization') is not None
            if controls_ok:
                desired = state.get('desired')
                result['initial_state_matches'] = (state.get('counter') == 36 and isinstance(desired, dict)
                    and desired.get('commit') == entry.OLD_CANDIDATE and desired.get('inventorySha256') == entry.OLD_INVENTORY
                    and desired.get('version') == entry.OLD_VERSION and desired.get('pushed') is True
                    and desired.get('status') in ('waiting-ci', 'retry-build', 'blocked')
                    and state.get('last_remote_sha') == entry.OLD_CANDIDATE and state.get('published') is None)
                report.step('local-candidate-without-fetch', lambda: readonly_candidate(ctx))
            remote_ok, remote = report.step('remote-lease-without-fetch', lambda: ctx.remote())
            if remote_ok:
                result['remote_lease_checked'] = True
                result['remote_lease_matches_original'] = remote == entry.OLD_CANDIDATE
    # These independent pure checks still identify manifest/schema failures
    # if an earlier control-plane check failed. They do not instantiate a patch.
    helper = module(package / 'go_erp_payment_nav_application_patch.py', code['go_erp_payment_nav_application_patch.py'])
    helper.configure(entry.BEFORE_SOURCE, entry.NEW_SOURCE, {entry.NAV: (entry.BEFORE_NAV, entry.AFTER_NAV)})
    owner_ok, owner = report.step('web-account', lambda: pwd.getpwnam('fasakha'))
    if owner_ok:
        scanner_ok, scanner = report.step('scanner', lambda: helper.pinned_scanner(package))
        if scanner_ok:
            sys.modules[scanner.__name__] = scanner
            source_ok, source = report.step('actual-source', lambda: scanner.digest(scanner.scan(entry.ROOT)))
            if source_ok:
                result['source_cohort'] = ('post-payment' if source == entry.BEFORE_SOURCE else
                                           'payment-and-navigation' if source == entry.NEW_SOURCE else
                                           'prepared-original' if source == entry.OLD_SOURCE else 'unrecognized')
        manifest_ok, pair = report.step('manifest-read', lambda: helper.optional_manifest(entry.ROOT, owner.pw_uid))
        if manifest_ok:
            raw, _ = pair
            result['manifest_present'] = raw is not None
            if raw is not None:
                parsed_ok, parsed = report.step('manifest-json', lambda: helper.unique_json(raw))
                if parsed_ok:
                    result['manifest_base_matches'] = isinstance(parsed, dict) and parsed.get('after_source') == entry.OLD_SOURCE
                    report.step('payment-manifest-proof', lambda: helper.payment_metadata(parsed))
                report.step('pure-manifest-reconciliation', lambda: helper.refreshed_manifest(raw,
                    {entry.NAV: {'blob': entry.BEFORE_NAV}}, {entry.NAV: {'blob': entry.AFTER_NAV}}))
        report.step('verify-before', lambda: helper.verify_before(entry.ROOT, package, owner.pw_uid))
    return {**result, 'checks': report.rows}


def main():
    try:
        result = run()
    except BaseException:
        result = {'format': 1, 'kind': 'go-erp-payment-navigation-readonly-diagnostic',
                  'application_writes': False, 'database_accessed': False, 'service_changed': False,
                  'candidate_fetch_performed': False, 'credentials_disclosed': False,
                  'checks': [{'step': 'diagnostic', 'ok': False, 'reason': 'PRIVATE_FAILURE'}]}
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
