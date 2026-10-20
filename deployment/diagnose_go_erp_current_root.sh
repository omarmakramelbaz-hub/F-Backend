#!/usr/bin/env bash
# Read current GO ERP source identities against the reviewed server baseline.
# No PHP bootstrap, database access, source publication, or application writes.
set -euo pipefail
umask 077

release=${1:-}
reviewed_release=91364fbb1210a0834b9d7b05835a97b5307bec19
project=/home/fasakha/public_html
directory=
cleanup() { [[ -z $directory ]] || rm -rf -- "$directory"; }
trap cleanup EXIT
stop() { echo "$1" >&2; exit 1; }

[[ $# == 1 && $release == "$reviewed_release" && $(id -u) -eq 0 && $(hostname -s) == srv1836524 ]] ||
  stop GO_ERP_DIAGNOSIS_INVALID_INVOCATION
[[ -d "$project/.git" && ! -L "$project" && ! -L "$project/.git" && $(realpath "$project") == "$project" ]] ||
  stop GO_ERP_DIAGNOSIS_PROJECT_UNSAFE
[[ -x /usr/bin/python3 && -x /usr/bin/timeout && -x /usr/sbin/runuser && -x /usr/bin/git ]] ||
  stop GO_ERP_DIAGNOSIS_RUNTIME_UNAVAILABLE
id fasakha >/dev/null 2>&1 || stop GO_ERP_DIAGNOSIS_ACCOUNT_UNAVAILABLE
directory=$(mktemp -d /tmp/go-erp-current-diagnosis.XXXXXXXX)

declare -A pins=(
  [deployment/diagnose_desktop_dashboard_current_source.py]=e7a7a8271481013a1c16167d6f7a6ff429687ef5
  [deployment/desktop_dashboard_server_preflight.py]=4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4
  [tests/fixtures/desktop-actual-server-baseline.json]=af07f6572c8a81f0f9bf1cd7b4cdfc0a207ab416
  [deployment/desktop_dashboard_server_manifest.json]=b6bb57619504f2093430d5b0fb9baa28bb2bb2a9
)
for path in "${!pins[@]}"; do
  target="$directory/${path##*/}"
  /usr/sbin/runuser -u fasakha -- /usr/bin/env -i PATH=/usr/bin:/bin HOME=/home/fasakha \
    GIT_OPTIONAL_LOCKS=0 GIT_NO_LAZY_FETCH=1 GIT_NO_REPLACE_OBJECTS=1 \
    /usr/bin/git --no-optional-locks -c core.fsmonitor=false -C "$project" \
    show "$release:$path" > "$target" 2>/dev/null || stop GO_ERP_DIAGNOSIS_REVIEWED_OBJECT_UNAVAILABLE
  actual=$(/usr/bin/python3 -I -B -c 'import hashlib,pathlib,sys; data=pathlib.Path(sys.argv[1]).read_bytes(); print(hashlib.sha1(b"blob "+str(len(data)).encode()+b"\0"+data).hexdigest())' "$target")
  [[ $actual == "${pins[$path]}" ]] || stop GO_ERP_DIAGNOSIS_REVIEWED_OBJECT_MISMATCH
  chmod 0644 "$target"
done

cat > "$directory/read-current.py" <<'PY'
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import sys

ROOT = Path('/home/fasakha/public_html')
RELEASE = '91364fbb1210a0834b9d7b05835a97b5307bec19'
PINS = {
    'diagnose_desktop_dashboard_current_source.py': 'e7a7a8271481013a1c16167d6f7a6ff429687ef5',
    'desktop_dashboard_server_preflight.py': '4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
    'desktop-actual-server-baseline.json': 'af07f6572c8a81f0f9bf1cd7b4cdfc0a207ab416',
    'desktop_dashboard_server_manifest.json': 'b6bb57619504f2093430d5b0fb9baa28bb2bb2a9',
}


def main():
    uid = pwd.getpwnam('fasakha').pw_uid
    if os.geteuid() != uid:
        raise ValueError()
    folder = Path(__file__).absolute().parent
    info = folder.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise ValueError()
    payload = {}
    for name, pin in PINS.items():
        path = folder / name
        item = path.lstat()
        if (not stat.S_ISREG(item.st_mode) or item.st_uid != 0 or item.st_nlink != 1
                or item.st_mode & 0o022 or item.st_size > 2 * 1024 * 1024):
            raise ValueError()
        data = path.read_bytes()
        actual = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
        if actual != pin:
            raise ValueError()
        payload[name] = data
    scanner = {'__name__': 'reviewed_go_erp_diagnosis_functions'}
    source = {'__name__': 'reviewed_go_erp_source_functions'}
    exec(compile(payload['diagnose_desktop_dashboard_current_source.py'], 'reviewed-diagnosis', 'exec'), scanner)
    exec(compile(payload['desktop_dashboard_server_preflight.py'], 'reviewed-preflight', 'exec'), source)
    fixture = json.loads(payload['desktop-actual-server-baseline.json'])
    manifest = json.loads(payload['desktop_dashboard_server_manifest.json'])
    before_profile = {row['path']: row['before_blob'] for row in manifest['sources']
                      if row['before_blob'] is not None}
    before_profile.update({row['path']: row['blob'] for row in manifest['protected']})
    fixture_profile = {path: row['blob'] for path, row in fixture['files'].items()}
    fixture_profile.update(fixture['gui'])
    if (len(fixture['files']) != 1164 or len(fixture['gui']) != 27
            or fixture['expected_head'] != manifest['expected_head']
            or fixture['source'] != manifest['before_source']
            or {**source['digest'](fixture['files']), 'framework': '8.83.29'} != fixture['source']
            or fixture_profile != before_profile):
        raise ValueError()
    for path in (ROOT, ROOT / '.git'):
        info = path.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != uid:
            raise ValueError()

    def head_reader():
        env = {'PATH': '/usr/bin:/bin', 'HOME': '/home/fasakha', 'GIT_OPTIONAL_LOCKS': '0',
               'GIT_NO_LAZY_FETCH': '1', 'GIT_NO_REPLACE_OBJECTS': '1'}
        result = subprocess.run(['/usr/bin/git', '--no-optional-locks', '-c', 'core.fsmonitor=false',
                                 '-C', str(ROOT), 'rev-parse', 'HEAD'], env=env,
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                check=True, timeout=15)
        head = result.stdout.decode('ascii').strip()
        if re.fullmatch('[a-f0-9]{40}', head) is None:
            raise ValueError()
        return head

    gui = list(dict.fromkeys([*fixture['gui'], *scanner['RELATED_GUI']]))
    before = scanner['snapshot'](ROOT, source, gui, [], head_reader)
    after = scanner['snapshot'](ROOT, source, gui, [], head_reader)
    result = scanner['report'](before, after, source, fixture, manifest,
                               {'rows': []}, release=RELEASE)
    output = json.dumps(result, ensure_ascii=True, separators=(',', ':'))
    if len(output.encode()) > 8 * 1024 * 1024:
        raise ValueError()
    print('DESKTOP_CURRENT_SOURCE_DIAGNOSIS_BEGIN')
    print(output)
    print('DESKTOP_CURRENT_SOURCE_DIAGNOSIS_END; DEPLOYMENT_PERFORMED=false')


try:
    main()
except BaseException:
    print('GO_ERP_DIAGNOSIS_STOPPED_WITHOUT_PRIVATE_OUTPUT', file=sys.stderr)
    sys.exit(1)
PY
chmod 0644 "$directory/read-current.py"
chmod 0755 "$directory"

if ! /usr/bin/timeout --signal=TERM --kill-after=5s 120s \
  /usr/sbin/runuser -u fasakha -- /usr/bin/python3 -I -B "$directory/read-current.py" \
  > "$directory/report.txt" 2> "$directory/error.txt"; then
  stop GO_ERP_DIAGNOSIS_STOPPED_NO_APPLICATION_CHANGES
fi
# The verified reader emits only bounded JSON identities and fixed receipts.
cat -- "$directory/report.txt"
