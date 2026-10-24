#!/usr/bin/env bash
# Inspect the current production-launch scope without changing application data.
set -euo pipefail
umask 077
test "$#" -eq 1
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
launch_revision=$1
[[ "$launch_revision" =~ ^[a-f0-9]{40}$ ]]
launch_root=/home/fasakha/public_html
launch_php=/opt/cpanel/ea-php82/root/usr/bin/php
test -x "$launch_php"
test -d "$launch_root"
test ! -L "$launch_root"
test "$(realpath -- "$launch_root")" = "$launch_root"
test "$(stat -c '%u' -- "$launch_root")" = "$(id -u fasakha)"
launch_stage=$(mktemp -d /var/tmp/launch-reset-inspection.XXXXXXXX)
trap 'rm -rf -- "$launch_stage"' EXIT
launch_git() {
    env GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null \
        GIT_NO_REPLACE_OBJECTS=1 GIT_TERMINAL_PROMPT=0 GIT_NO_LAZY_FETCH=1 \
        git --git-dir="$launch_stage/release.git" -c credential.helper= \
        -c core.hooksPath=/dev/null -c core.attributesFile=/dev/null \
        -c submodule.recurse=false "$@"
}
git -c core.hooksPath=/dev/null init --bare --quiet "$launch_stage/release.git"
launch_git fetch --quiet --depth=1 --no-tags --no-recurse-submodules \
    https://github.com/omarmakramelbaz-hub/F-Backend.git "$launch_revision"
test "$(launch_git rev-parse "$launch_revision^{commit}")" = "$launch_revision"
mkdir -m 0755 "$launch_stage/public"
for launch_file in inspect.php payroll_inspect.php inspected_schema_oct19.json; do
    launch_git show "$launch_revision:deployment/launch_reset_20261025/$launch_file" \
        > "$launch_stage/public/$launch_file"
    chmod 0644 "$launch_stage/public/$launch_file"
done
chmod 0711 "$launch_stage"
/usr/bin/python3 -I -B <<'PY'
import hashlib, json, os, pwd, re, stat, subprocess
from pathlib import Path

unit_pattern=re.compile(r'(?:fasakhansta-[A-Za-z0-9@_.-]+|go-erp-[A-Za-z0-9@_.-]+|laravel-queue)\.(?:service|timer|path)\Z')
def run(args):
    result=subprocess.run(['/usr/bin/systemctl',*args],stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=20)
    if result.returncode or len(result.stdout)>1024*1024:raise ValueError('SYSTEMD_READ_FAILED')
    return result.stdout.decode('utf-8','strict')
def read_private(path):
    try:fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    except FileNotFoundError:return None
    try:
        info=os.fstat(fd)
        if not(stat.S_ISREG(info.st_mode) and info.st_uid in (0,pwd.getpwnam('fasakha').pw_uid)
            and info.st_nlink==1 and not info.st_mode&0o022 and info.st_size<=16384):raise ValueError('ACTIVATION_FILE_UNSAFE')
        raw=os.read(fd,16385);after=os.fstat(fd)
        if len(raw)!=info.st_size or (info.st_ino,info.st_mtime_ns,info.st_ctime_ns,info.st_size)!=(after.st_ino,after.st_mtime_ns,after.st_ctime_ns,after.st_size):raise ValueError('ACTIVATION_FILE_CHANGED')
        value=json.loads(raw)
        day=value.get('starts_on') if isinstance(value,dict) else None
        return {'present':True,'starts_on':day if isinstance(day,str) and re.fullmatch(r'20[0-9]{2}-[0-9]{2}-[0-9]{2}',day) else None,'sha256':hashlib.sha256(raw).hexdigest()}
    finally:os.close(fd)
try:
    names=sorted({line.split()[0] for line in run(['list-unit-files','--type=service,timer,path','--no-legend','--no-pager','--plain']).splitlines() if line.split() and unit_pattern.fullmatch(line.split()[0])})
    if len(names)>100:raise ValueError('UNIT_LIMIT')
    units=[]
    if names:
        output=run(['show',*names,'--no-pager','-p','Id','-p','ActiveState','-p','SubState','-p','UnitFileState','-p','MainPID','-p','FragmentPath'])
        for section in output.strip().split('\n\n'):
            value=dict(line.split('=',1) for line in section.splitlines() if '=' in line)
            if value.get('Id') not in names:raise ValueError('UNIT_IDENTITY_CHANGED')
            row={'unit':value['Id']}
            for field in ('ActiveState','SubState','UnitFileState'):
                item=value.get(field,'');row[field]=item if re.fullmatch(r'[a-z-]{0,40}',item) else None
            pid=value.get('MainPID','');row['MainPID']=int(pid) if re.fullmatch(r'[0-9]{1,12}',pid) else None
            fragment=value.get('FragmentPath','');row['fragment_sha256']=None
            if fragment and any(fragment.startswith(prefix) for prefix in ('/etc/systemd/system/','/usr/lib/systemd/system/','/run/systemd/system/')):
                fd=os.open(fragment,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
                try:
                    info=os.fstat(fd)
                    if not(stat.S_ISREG(info.st_mode) and info.st_uid==0 and info.st_nlink==1 and not info.st_mode&0o022 and info.st_size<=65536):raise ValueError('UNIT_FILE_UNSAFE')
                    raw=os.read(fd,65537)
                    if len(raw)!=info.st_size:raise ValueError('UNIT_FILE_CHANGED')
                    row['fragment_sha256']=hashlib.sha256(raw).hexdigest()
                finally:os.close(fd)
            units.append(row)
    activation=read_private(Path('/home/fasakha/employee-auto-absence-backups/activation.json'))
    result={'format':1,'kind':'launch-reset-20261025-system-inspection','ok':True,'units':units,
        'automatic_absence':activation or {'present':False},'application_changed':False,'services_changed':False,'database_accessed':False}
except BaseException as error:
    message=str(error)
    result={'format':1,'kind':'launch-reset-20261025-system-inspection','ok':False,
        'code':message if isinstance(error,ValueError) and re.fullmatch(r'[A-Z_]{1,80}',message) else 'PRIVATE_INSPECTION_FAILURE',
        'application_changed':False,'services_changed':False,'database_accessed':False}
print(json.dumps(result,sort_keys=True))
PY
runuser -u fasakha -- "$launch_php" -d display_errors=0 -d log_errors=0 \
    -d opcache.enable_cli=0 "$launch_stage/public/inspect.php" "$launch_root"
printf '%s\n' LAUNCH_RESET_INSPECTION_READY_NO_DATA_CHANGED
