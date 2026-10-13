#!/usr/bin/env python3
"""Inspect only metadata for the WhatsApp order repair's preflight paths."""
import argparse
import itertools
import json
import os
import pathlib
import pwd
import stat
import sys


APP_ROOT = pathlib.Path('/home/fasakha/public_html')
MAX_CONFIG_ENTRIES = 500
OPTIONAL_FILES = frozenset((
    'bootstrap/cache/config.php',
    'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php',
))
# Match the frozen 425ccb37 order-evidence repair's configuration, protected
# sources and publication sources. No application/helper code is imported.
FILES = (
    '.env', 'bootstrap/cache/config.php',
    'app/Support/WhatsAppOrderExtraction.php',
    'app/Support/WhatsAppInboxProtocol.php',
    'app/Services/Dashboard/FasakhanstaDeliveryOrigin.php',
    'app/Services/Dashboard/PhoneDelivery.php',
    'app/Services/Dashboard/PosServiceTicket.php',
    'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php',
    'app/Services/Dashboard/WhatsAppInboxMessage.php',
    'app/Services/Dashboard/WhatsAppOrderAiProvider.php',
    'app/Services/Dashboard/WhatsAppInboxConsumer.php',
    'app/Services/Dashboard/WhatsAppReplyService.php',
    'app/Http/Controllers/Dashboard/WhatsAppInboxController.php',
    'app/Services/Dashboard/WhatsAppOrderWorkflow.php',
)


class SafeError(Exception):
    """Only fixed diagnostic codes may be printed."""


def _metadata(path):
    # lstat does not follow the final symlink and never opens file contents.
    return os.lstat(path)


def _path_label(path, root):
    try:
        return str(path.relative_to(root))
    except ValueError:
        return str(path)


def _kind(mode):
    for check, name in ((stat.S_ISREG, 'regular'), (stat.S_ISDIR, 'directory'),
                        (stat.S_ISLNK, 'symlink'), (stat.S_ISFIFO, 'fifo'),
                        (stat.S_ISSOCK, 'socket'), (stat.S_ISCHR, 'character'),
                        (stat.S_ISBLK, 'block')):
        if check(mode):
            return name
    return 'other'


def _issue(path, root, reason, info=None):
    result = {'path': _path_label(path, root), 'reason': reason}
    if info is not None:
        result.update(uid=info.st_uid, gid=info.st_gid,
                      mode=format(stat.S_IMODE(info.st_mode), '04o'),
                      type=_kind(info.st_mode))
    return result


def _ancestor_issue(path, uid, root):
    for parent in reversed(path.parents):
        try:
            info = _metadata(parent)
        except FileNotFoundError:
            # The frozen publisher permits absent optional files even when
            # their directories have not yet been created.
            continue
        except OSError:
            return _issue(parent, root, 'ANCESTOR_METADATA_UNAVAILABLE')
        owners = (uid,) if parent == root or root in parent.parents else (0, uid)
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in owners:
            return _issue(parent, root, 'ANCESTOR_OWNER_OR_TYPE', info)
    return None


def inspect(root, uid):
    """Fixture roots are accepted here; the command line accepts APP_ROOT only."""
    root = pathlib.Path(root)
    if not root.is_absolute() or '..' in root.parts:
        raise SafeError('DIAGNOSTIC_ROOT_INVALID')
    issues, seen = [], set()
    checked = 0

    def record(value):
        key = (value['path'], value['reason'])
        if key not in seen:
            seen.add(key)
            issues.append(value)

    def examine(path, directory=False, optional=False):
        nonlocal checked
        fault = _ancestor_issue(path, uid, root)
        if fault is not None:
            record(fault)
            return False
        try:
            info = _metadata(path)
        except FileNotFoundError:
            if not optional:
                record(_issue(path, root, 'REQUIRED_PATH_MISSING'))
            return False
        except OSError:
            record(_issue(path, root, 'METADATA_UNAVAILABLE'))
            return False
        checked += 1
        valid_type = stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode)
        if not valid_type or info.st_uid != uid:
            record(_issue(path, root, 'OWNER_OR_TYPE', info))
            return False
        return True

    # Reject a symlink or foreign ancestor before listing project directories.
    root_ready = examine(root, directory=True)
    names = list(FILES)
    complete = root_ready
    if root_ready:
        examine(root/'.git', directory=True)
        if examine(root/'config', directory=True):
            try:
                entries = list(itertools.islice((root/'config').iterdir(), MAX_CONFIG_ENTRIES + 1))
                if len(entries) > MAX_CONFIG_ENTRIES:
                    complete = False
                    record(_issue(root/'config', root, 'CONFIG_ENTRY_LIMIT_REACHED'))
                names.extend(str(p.relative_to(root)) for p in sorted(entries[:MAX_CONFIG_ENTRIES]))
            except OSError:
                complete = False
                record(_issue(root/'config', root, 'CONFIG_LISTING_UNAVAILABLE'))
        else:
            complete = False
        for name in names:
            examine(root/name, optional=name in OPTIONAL_FILES)
    status = ('INCOMPLETE' if not complete else
              'ISSUES_FOUND' if issues else 'NO_OWNER_OR_TYPE_ISSUES_FOUND')
    return {'diagnostic': 'WHATSAPP_ORDER_FILE_METADATA', 'writes': 0,
            'status': status, 'expected_uid': uid, 'checked': checked,
            'config_scan_complete': complete, 'issues': issues}


class FixedArgumentParser(argparse.ArgumentParser):
    def error(self, message):
        raise SafeError('DIAGNOSTIC_ARGUMENTS_INVALID')


def main(argv=None):
    try:
        parser = FixedArgumentParser(add_help=False)
        parser.add_argument('--root', required=True)
        args = parser.parse_args(argv)
        root = pathlib.Path(args.root)
        if root != APP_ROOT or not root.is_absolute() or '..' in root.parts:
            raise SafeError('DIAGNOSTIC_ROOT_INVALID')
        try:
            uid = pwd.getpwnam('fasakha').pw_uid
        except KeyError:
            raise SafeError('DIAGNOSTIC_OWNER_ACCOUNT_UNAVAILABLE') from None
        if os.geteuid() != uid:
            raise SafeError('DIAGNOSTIC_EXECUTION_CONTEXT_INVALID')
        result = inspect(root, uid)
        print(json.dumps(result, indent=2, ensure_ascii=True))
        return 0
    except BaseException as error:
        reason = str(error) if isinstance(error, SafeError) else 'DIAGNOSTIC_STOPPED'
        print(json.dumps({'diagnostic': 'WHATSAPP_ORDER_FILE_METADATA',
                          'writes': 0, 'status': 'FAILED', 'reason': reason}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
