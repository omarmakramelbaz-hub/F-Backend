#!/usr/bin/env python3
"""Read-only host preflight. Never print command output containing account credentials."""
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import sys

PROJECT = Path('/home/fasakha/public_html')
PHP = Path('/opt/cpanel/ea-php82/root/usr/bin/php')
ACCOUNT = 'fasakha'


def uapi(binary, module, function):
    # This diagnostic deliberately has no generic cPanel command runner.
    if (module, function) not in {('Mysql', 'list_databases'), ('DomainInfo', 'list_domains')}:
        raise ValueError('GO_OWNER_AUDIT_READ_ONLY_UAPI_REQUIRED')
    if binary is None:
        return None
    try:
        done = subprocess.run([str(binary), '--user=' + ACCOUNT, '--output=json', module, function],
                              capture_output=True, text=True, timeout=30, check=True)
        result = json.loads(done.stdout).get('result', {})
        if result.get('status') != 1:
            return None
        return result.get('data')
    except (subprocess.SubprocessError, ValueError, OSError):
        return None


def host_report():
    binary = next((p for p in [Path('/usr/local/cpanel/bin/uapi'), Path('/usr/bin/uapi')]
                   if p.is_file() and os.access(p, os.X_OK)), None)
    databases = uapi(binary, 'Mysql', 'list_databases')
    domains = uapi(binary, 'DomainInfo', 'list_domains')
    quota = None
    try:
        for line in Path('/var/cpanel/users/' + ACCOUNT).read_text().splitlines():
            if line.startswith('MAXSQL='):
                value = line.partition('=')[2]
                if re.fullmatch(r'(?:[0-9]+|unlimited)', value):
                    quota = value
    except OSError:
        pass
    disk = shutil.disk_usage(PROJECT)
    return {'cpanel_uapi_available': binary is not None,
            'cpanel_mysql_read_access': databases is not None,
            'cpanel_database_count': len(databases) if isinstance(databases, list) else None,
            'cpanel_database_quota': quota,
            'cpanel_domains_read_access': isinstance(domains, dict),
            'main_domain': domains.get('main_domain') if isinstance(domains, dict) else None,
            'mysqldump_available': any(os.access(p, os.X_OK) for p in
                ['/usr/bin/mariadb-dump', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump']),
            'home_free_mib': disk.free // (1024 * 1024),
            'home_total_mib': disk.total // (1024 * 1024),
            'cron_directory_available': Path('/etc/cron.d').is_dir(),
            'root_cron_installed': False}


def main():
    if len(sys.argv) != 3 or sys.argv[2] != '--inspect':
        raise ValueError('GO_OWNER_AUDIT_ARGUMENTS_REQUIRED')
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
        raise ValueError('GO_OWNER_AUDIT_ROOT_AND_HOST_REQUIRED')
    if PROJECT.is_symlink() or PROJECT.resolve() != PROJECT or not PHP.is_file():
        raise ValueError('GO_OWNER_AUDIT_PROJECT_REQUIRED')
    script = Path(sys.argv[1])
    if script.is_symlink() or not script.is_file() or script.name != 'inspect_owner_dashboards.php':
        raise ValueError('GO_OWNER_AUDIT_SCRIPT_REQUIRED')
    done = subprocess.run(['runuser', '-u', ACCOUNT, '--', str(PHP), '-d', 'display_errors=0',
                           '-d', 'log_errors=0', str(script), str(PROJECT), '--inspect'],
                          capture_output=True, text=True, timeout=90)
    if done.returncode:
        # Do not forward PHP/bootstrap stderr. It can contain configuration or query bindings.
        error = done.stderr.strip()
        raise ValueError(error if re.fullmatch(r'GO_OWNER_AUDIT_[A-Z_]+', error)
                         else 'GO_OWNER_AUDIT_DATABASE_OR_RUNTIME_CHECK_FAILED')
    report = json.loads(done.stdout)
    if report.get('status') != 'GO_OWNER_DASHBOARDS_INSPECTED' or report.get('read_only') is not True:
        raise ValueError('GO_OWNER_AUDIT_REPORT_REQUIRED')
    report['host'] = host_report()
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        message = str(error)
        print(message if re.fullmatch(r'GO_OWNER_AUDIT_[A-Z_]+', message)
              else 'GO_OWNER_AUDIT_HOST_CHECK_FAILED', file=sys.stderr)
        sys.exit(1)
