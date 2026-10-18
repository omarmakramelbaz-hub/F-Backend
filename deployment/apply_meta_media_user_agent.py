#!/usr/bin/env python3
"""Install one pinned media service only after a successful read-only A/B probe."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile

OLD_SHA = '55ee54ff9c081e41bad1aea1d639f73396e4edc1bbd22acfe6190411d5d5cfcd'
NEW_SHA = 'b15740b84d0ffc7aca4fdbc7324ed5d0848fe7f61eff61a3ac2d3786c3202067'
DIAGNOSTIC_SHA = 'adcf1cb7553aeeda803715dad7c6cf100eb21a79425bcdcaf69b431da6e22b7b'
SERVICE = 'app/Services/Dashboard/MetaMediaService.php'


class Refused(Exception):
    def __init__(self, category, evidence=None):
        super().__init__(category)
        self.evidence = evidence


def digest(data):
    return hashlib.sha256(data).hexdigest()


def read_file(path):
    if path.is_symlink() or not path.is_file():
        raise Refused('FILE_REJECTED')
    return path.read_bytes()


def run_probe(root, php, diagnostic):
    result = subprocess.run([str(php), str(diagnostic)], cwd=root, capture_output=True,
                            timeout=45, check=False)
    if result.returncode or len(result.stdout) > 65536:
        raise Refused('PROBE_FAILED')
    try:
        data = json.loads(result.stdout)
    except (ValueError, UnicodeError):
        raise Refused('PROBE_FAILED') from None
    if not isinstance(data, dict) or data.get('status') != 'COMPLETE' or data.get('phase') != 'COMPLETE':
        raise Refused('PROBE_INCOMPLETE')
    if any(data.get(key) != 0 for key in ['writes', 'message_sends', 'graph_gets', 'redirects_followed']):
        raise Refused('PROBE_CONTRACT_REJECTED')
    return data


def channel(data, name):
    rows = data.get('channels', [])
    matches = [row for row in rows if isinstance(row, dict) and row.get('channel') == name]
    if len(matches) != 1:
        raise Refused('CHANNEL_SAMPLE_UNAVAILABLE')
    return matches[0]


def ready(result):
    return isinstance(result, dict) and result.get('status') == 'READY' \
        and result.get('category') == 'VALID_IMAGE' and result.get('http_status') == 200 \
        and result.get('deployed_validator_accepts') is True \
        and result.get('resolver_status') == 'PUBLIC_IP_PINNED' \
        and isinstance(result.get('bytes'), int) and 0 < result['bytes'] <= 20971520


def snapshot(data):
    """Keep diagnostic result facts; no raw process output or upstream values."""
    out = {'status': data.get('status') if data.get('status') in ['COMPLETE', 'FAILED'] else 'FAILED', 'channels': []}
    for name in ['messenger', 'instagram']:
        item = {'channel': name}
        sample = channel(data, name)
        for result_key in ['result', 'browser_agent_result']:
            if not isinstance(sample.get(result_key), dict):
                continue
            result = sample[result_key]
            clean = {key: result[key] for key in ['http_status', 'bytes', 'deployed_validator_accepts']
                     if isinstance(result.get(key), (int, bool))}
            for key in ['status', 'category', 'resolver_status', 'redirect_path_class']:
                value = result.get(key)
                if isinstance(value, str) and len(value) <= 64 and all(c in 'ABCDEFGHIJKLMNOPQRSTUVWXYZ_' for c in value):
                    clean[key] = value
            item[result_key] = clean
        out['channels'].append(item)
    return out


def verify(data, expected):
    if data.get('service_sha256') != expected:
        raise Refused('RUNTIME_SOURCE_DRIFT')
    if not ready(channel(data, 'instagram').get('result')):
        raise Refused('INSTAGRAM_VERIFY_FAILED')


def atomic_write(target, data, metadata):
    fd, name = tempfile.mkstemp(prefix='.meta-media-agent-', dir=target.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchown(stream.fileno(), metadata.st_uid, metadata.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(metadata.st_mode))
        os.replace(name, target)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def apply(root, php, source, diagnostic):
    root, source, diagnostic = Path(root).resolve(), Path(source), Path(diagnostic)
    candidate, probe_bytes = read_file(source), read_file(diagnostic)
    if digest(candidate) != NEW_SHA or digest(probe_bytes) != DIAGNOSTIC_SHA:
        raise Refused('RELEASE_SOURCE_REJECTED')
    target = root / SERVICE
    if any(parent.is_symlink() for parent in [target, *target.parents] if parent != root.parent):
        raise Refused('TARGET_PATH_REJECTED')
    original = read_file(target)
    current = digest(original)
    if current not in [OLD_SHA, NEW_SHA]:
        raise Refused('DEPLOYED_SOURCE_DRIFT')
    lint = subprocess.run([str(php), '-l', str(source)], capture_output=True, timeout=10, check=False)
    if lint.returncode:
        raise Refused('PHP_LINT_FAILED')
    preflight = run_probe(root, php, diagnostic)
    if preflight.get('service_sha256') != current:
        raise Refused('RUNTIME_SOURCE_DRIFT')
    if current == NEW_SHA:
        verify(preflight, NEW_SHA)
        return {'status': 'ALREADY_READY', 'writes': 0, 'instagram': 'VALID_IMAGE', 'postflight': snapshot(preflight)}
    instagram = channel(preflight, 'instagram')
    baseline = instagram.get('result', {})
    if baseline.get('http_status') != 302 or baseline.get('category') != 'REDIRECT_NOT_FOLLOWED' \
            or baseline.get('redirect_path_class') != 'UNSUPPORTED_BROWSER' \
            or baseline.get('redirect_observed_host') != 'www.facebook.com':
        raise Refused('BASELINE_NOT_REPRODUCED', preflight)
    if not ready(instagram.get('browser_agent_result')):
        raise Refused('BROWSER_CANDIDATE_REJECTED', preflight)
    messenger_was_ready = ready(channel(preflight, 'messenger').get('result'))
    metadata = target.stat()
    backup_root = root.parent / 'meta-media-agent-backups'
    if backup_root.is_symlink():
        raise Refused('BACKUP_PATH_REJECTED')
    backup_root.mkdir(mode=0o700, exist_ok=True)
    backup = Path(tempfile.mkdtemp(prefix='release-', dir=backup_root))
    os.chmod(backup, 0o700)
    (backup / 'MetaMediaService.php').write_bytes(original)
    os.chmod(backup / 'MetaMediaService.php', 0o600)
    if read_file(target) != original:
        raise Refused('PREWRITE_SOURCE_DRIFT')
    atomic_write(target, candidate, metadata)
    try:
        if digest(read_file(target)) != NEW_SHA:
            raise Refused('POSTWRITE_SOURCE_DRIFT')
        postflight = run_probe(root, php, diagnostic)
        verify(postflight, NEW_SHA)
        if messenger_was_ready and not ready(channel(postflight, 'messenger').get('result')):
            raise Refused('MESSENGER_VERIFY_FAILED')
    except Exception:
        if digest(read_file(target)) == NEW_SHA:
            atomic_write(target, original, metadata)
            raise Refused('VERIFY_FAILED_ROLLED_BACK') from None
        raise Refused('VERIFY_FAILED_CONCURRENT_DRIFT') from None
    return {'status': 'APPLIED_READY', 'writes': 1, 'instagram': 'VALID_IMAGE', 'backup': str(backup),
            'preflight': snapshot(preflight), 'postflight': snapshot(postflight)}


def main():
    parser = argparse.ArgumentParser()
    for name in ['root', 'php', 'source', 'diagnostic']:
        parser.add_argument('--' + name, required=True)
    args = parser.parse_args()
    try:
        result = apply(args.root, args.php, args.source, args.diagnostic)
    except Refused as error:
        result = {'status': 'REFUSED', 'category': str(error)}
        if error.evidence is not None:
            result['preflight'] = snapshot(error.evidence)
    except Exception:
        result = {'status': 'REFUSED', 'category': 'INSTALL_UNAVAILABLE'}
    print(json.dumps(result, sort_keys=True))
    return 0 if result['status'] in ['APPLIED_READY', 'ALREADY_READY'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
