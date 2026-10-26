#!/usr/bin/env python3
"""Read-only attestation of this application's active cPanel PHP82 handler."""
import glob
import hashlib
import os
from pathlib import Path
import re
import stat

DOMAIN = 'fasakhaninja.com'
USER = 'fasakha'
ROOT = Path('/home/fasakha/public_html')
APACHE = Path('/etc/apache2/conf/httpd.conf')
USERDATA = Path('/var/cpanel/userdata/fasakha/fasakhaninja.com')
FPM_YAML = Path('/var/cpanel/userdata/fasakha/fasakhaninja.com.php-fpm.yaml')
POOL = Path('/opt/cpanel/ea-php82/root/etc/php-fpm.d/fasakhaninja.com.conf')
FPM_CONFIG = Path('/opt/cpanel/ea-php82/root/etc/php-fpm.conf')
FPM_EXE = '/opt/cpanel/ea-php82/root/usr/sbin/php-fpm'
SOCKET_ROOT = '/opt/cpanel/ea-php82/root/usr/var/run/php-fpm/'
INCLUDE_ROOT = '/etc/apache2/conf.d/userdata/'


class Stop(Exception):
    pass


def need(value, code='PHP_HANDLER_REVIEW_REQUIRED'):
    if not value:
        raise Stop(code)


def scalar(value):
    value = value.strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in ('"', "'"):
        value = value[1:-1]
    need(bool(value) and '\x00' not in value and '\r' not in value)
    return value


def fields(raw, names, separator):
    result = {}
    for row in raw.splitlines():
        row = row.strip()
        if not row or row.startswith(('#', ';')) or separator not in row:
            continue
        key, value = row.split(separator, 1)
        key = key.strip()
        if key in names:
            need(key not in result)
            result[key] = scalar(value)
    return result


def pool_binding(raw, documentroot):
    sections = re.findall(r'^\s*\[([^\]\r\n]+)\]\s*$', raw, re.M)
    need(len(sections) == 1)
    value = fields(raw, {'user', 'group', 'listen', 'php_admin_value[doc_root]', 'php_value[doc_root]'}, '=')
    need(value.get('user') == USER and value.get('group') == USER)
    socket = value.get('listen', '')
    need(re.fullmatch(re.escape(SOCKET_ROOT) + r'[A-Za-z0-9_.-]+\.sock', socket))
    for name in ('php_admin_value[doc_root]', 'php_value[doc_root]'):
        if name in value:
            need(value[name] == documentroot)
    return socket


def expand_includes(rows, reader=None, *, depth=0, ancestors=()):
    need(depth <= 4)
    result = []
    for row in rows:
        stripped = row.strip()
        matched = re.match(r'(?i)^(Include|IncludeOptional)\s+(.+)$', stripped)
        if not matched:
            need(not re.match(r'(?i)^Include(?:Optional)?\b', stripped))
            result.append(row); continue
        need(reader is not None)
        for name, value in reader(scalar(matched[2]), matched[1].lower() == 'includeoptional'):
            need(name not in ancestors)
            result += expand_includes(value.splitlines(), reader, depth=depth+1, ancestors=(*ancestors,name))
        need(len(result) <= 20000)
    return result


def secure_existing_prefix(path, lstat=None):
    lstat = lstat or os.lstat
    path = Path(path)
    need(path.is_absolute())
    for ancestor in (*reversed(path.parents), path):
        try:
            value = lstat(ancestor)
        except FileNotFoundError:
            return False
        need(stat.S_ISDIR(value.st_mode) and value.st_uid == 0 and not value.st_mode & 0o022)
    return True


def domain_includes(read, *, globber=None, directory_check=None):
    globber = globber or glob.glob
    directory_check = directory_check or secure_existing_prefix
    total = {'files':0,'bytes':0}
    def load(pattern, optional):
        matched = re.fullmatch(re.escape(INCLUDE_ROOT)
            + r'(?:std|ssl)/2_4/fasakha/fasakhaninja\.com/((?:[A-Za-z0-9_.-]+/)*(?:[A-Za-z0-9_.-]+|\*)\.conf)', pattern)
        need(matched and all(part not in ('.','..') for part in matched[1].split('/')))
        parent_exists = directory_check(Path(pattern).parent)
        paths = sorted(globber(pattern)) if parent_exists else []
        need(len(paths) <= 32)
        need(paths or optional or pattern.endswith('/*.conf'))
        result = []
        for name in paths:
            need(Path(name).parent == Path(pattern).parent and name.endswith('.conf'))
            total['files'] += 1
            need(total['files'] <= 32)
            value = read(Path(name), 256*1024)
            total['bytes'] += len(value.encode('utf8'))
            need(total['bytes'] <= 2*1024**2)
            result.append((name,value))
        return result
    return load


def literal_proxy_exclusion(row, containers, guards):
    """Recognize only literal, non-routing ProxyPass path ! exclusions.

    Apache documents ! as an exclusion, not a backend. Positive mappings,
    implicit Location paths, interpolation and options remain unreviewed.
    https://httpd.apache.org/docs/2.4/mod/mod_proxy.html#proxypass
    """
    match = re.fullmatch(r'(?i)ProxyPass\s+(?:"([^"\\]*)"|([^\s"\\]+))\s+(?:"!"|!)', row)
    if not match or len(containers) != len(guards) or any(name != 'ifmodule' for name in containers) \
            or any(guard not in ('proxy_module', 'mod_proxy.c') for guard in guards):
        return False
    path = match[1] if match[1] is not None else match[2]
    return bool(re.fullmatch(r'/[A-Za-z0-9._~/-]*', path)) and '//' not in path \
        and all(part not in ('.', '..') for part in path.split('/'))


def virtual_hosts(raw, dump, modules, documentroot, socket, *, include_reader=None):
    """Bind every loaded name-vhost for this domain to one exact pool socket."""
    need('proxy_module' in modules and 'proxy_fcgi_module' in modules)
    references = set()
    for row in dump.splitlines():
        if re.search(r'\b(?:namevhost|default server)\s+' + re.escape(DOMAIN) + r'\s', row):
            match = re.search(r'\((/[^():]+):(\d+)\)\s*$', row)
            need(match and match[1] == str(APACHE))
            references.add(int(match[2]))
    need(bool(references))
    rows = raw.splitlines()
    accepted = []
    for line in sorted(references):
        need(1 <= line <= len(rows) and re.fullmatch(r'\s*<VirtualHost\s+[^>]+>\s*', rows[line-1], re.I))
        end = next((i for i in range(line, len(rows)) if re.fullmatch(r'\s*</VirtualHost>\s*', rows[i], re.I)), None)
        need(end is not None)
        block = expand_includes(rows[line:end], include_reader)
        directives = []
        guards = []
        matches = []
        containers = []
        handlers = []
        for row in block:
            row = row.strip()
            if not row or row.startswith('#'):
                continue
            tag = re.fullmatch(r'<(/?)([A-Za-z]+)(?:\s+.*)?>', row)
            if tag:
                name = tag[2].lower()
                if tag[1]:
                    need(bool(containers) and containers[-1] == name)
                    containers.pop()
                else:
                    need(not name.startswith('if') or name == 'ifmodule')
                    containers.append(name)
            # Only bounded, root-owned domain-specific includes are expanded.
            # Every handler/conditional in that content follows the same rules.
            if re.match(r'(?i)ProxyPass\b', row):
                need(literal_proxy_exclusion(row, containers, guards))
                continue
            need(not re.match(r'(?i)(?:Include|IncludeOptional|ProxyPassMatch)\b', row))
            opened = re.fullmatch(r'<IfModule\s+([^>]+)>', row, re.I)
            if opened:
                guards.append(opened[1].strip()); continue
            if re.fullmatch(r'</IfModule>', row, re.I):
                need(bool(guards)); guards.pop(); continue
            matched = re.fullmatch(r'<FilesMatch\s+(.+)>', row, re.I)
            if matched:
                matches.append(scalar(matched[1])); continue
            if re.fullmatch(r'</FilesMatch>', row, re.I):
                need(bool(matches)); matches.pop(); continue
            if re.match(r'(?i)<(?:If|IfDefine)\b', row):
                raise Stop('PHP_HANDLER_REVIEW_REQUIRED')
            parts = row.split(None, 1)
            if len(parts) != 2:
                continue
            name, argument = parts[0].lower(), scalar(parts[1])
            if name in ('servername', 'documentroot'):
                directives.append((name, argument))
            if name == 'sethandler':
                need(all(name in ('ifmodule','filesmatch') for name in containers))
                need(all(g in ('proxy_fcgi_module', 'mod_proxy_fcgi.c', 'proxy_module', 'mod_proxy.c') for g in guards))
                need(matches == [r'\.(phtml|php[0-9]*)$'] or matches == [r'\.php$'])
                handlers.append(argument)
        need(not guards and not matches and not containers
            and [v for k,v in directives if k == 'servername'] == [DOMAIN]
            and [v for k,v in directives if k == 'documentroot'] == [documentroot])
        need(handlers == ['proxy:unix:' + socket + '|fcgi://' + DOMAIN]
            or handlers == ['proxy:unix:' + socket + '|fcgi://' + DOMAIN + '/'])
        accepted.append(line)
    return accepted


def service(engine, unit, *, active, reloadable=None, pid=False):
    names = ['ActiveState']
    if reloadable is not None:
        names.append('CanReload')
    if pid:
        names.append('MainPID')
    args = ['/usr/bin/systemctl', 'show', unit]
    for name in names:
        args += ['-p', name]
    raw = engine.run(args)
    rows = raw.splitlines()
    values = fields(raw, set(names), '=')
    need(len(rows) == len(names) and set(values) == set(names)
        and values['ActiveState'] == active)
    if reloadable is not None:
        need(values['CanReload'] == reloadable)
    if pid:
        need(re.fullmatch(r'[1-9][0-9]{0,9}', values['MainPID']))
        return int(values['MainPID'])


def live_socket(pid, socket, *, read_text=None, readlink=None, listdir=None):
    read_text = read_text or (lambda name: Path(name).read_text())
    readlink = readlink or os.readlink
    listdir = listdir or os.listdir
    need(readlink('/proc/' + str(pid) + '/exe') == FPM_EXE)
    raw = read_text('/proc/net/unix')
    need(len(raw) <= 2 * 1024**2)
    inodes = set()
    for row in raw.splitlines()[1:]:
        parts = row.split()
        if len(parts) == 8 and parts[7] == socket:
            need(re.fullmatch(r'[1-9][0-9]*', parts[6]))
            # Linux copies a listening stream's address to its accepted peers.
            # Those rows may share this pathname, but cannot prove a listener.
            if parts[3] == '00000000':
                need(parts[4] == '0001' and parts[5] in ('01', '02', '03', '04'))
                continue
            need(parts[3] == '00010000' and parts[4] == '0001' and parts[5] == '01')
            inodes.add(parts[6])
    need(len(inodes) == 1)
    inode = next(iter(inodes)); folder = '/proc/' + str(pid) + '/fd'
    names = listdir(folder)
    need(len(names) <= 8192)
    found = False
    for name in names:
        if not re.fullmatch(r'[0-9]+', name):
            continue
        try:
            target = readlink(folder + '/' + name)
        except FileNotFoundError:
            continue
        found |= target == 'socket:[' + inode + ']'
    need(found)
    return inode


def verify(engine, *, runtime_io=None):
    """Return private hashes/identities only; never write or disclose config text."""
    pid = service(engine, 'ea-php82-php-fpm.service', active='active', reloadable='yes', pid=True)
    service(engine, 'httpd.service', active='active', reloadable='yes')
    service(engine, 'lsws.service', active='inactive')
    files = {}
    def read(name, maximum=2*1024**2):
        raw = engine.read_file(name, maximum, uid=0, modes=(0o600,0o640,0o644))[0]
        digest = hashlib.sha256(raw).hexdigest()
        need(str(name) not in files or files[str(name)] == digest)
        files[str(name)] = digest
        return raw.decode('utf8', errors='strict')
    userdata = fields(read(USERDATA), {'documentroot', 'servername', 'user', 'group', 'phpversion'}, ':')
    documentroot = userdata.get('documentroot')
    need(documentroot in (str(ROOT), str(ROOT / 'public'))
        and userdata.get('servername') == DOMAIN and userdata.get('user') == USER
        and userdata.get('group') == USER and userdata.get('phpversion') == 'ea-php82')
    read(FPM_YAML)
    config = read(FPM_CONFIG)
    includes = fields(config, {'include'}, '=')
    need(includes.get('include') == str(POOL.parent / '*.conf'))
    socket = pool_binding(read(POOL), documentroot)
    dump = engine.run(['/usr/sbin/httpd', '-t', '-D', 'DUMP_VHOSTS'])
    modules = set(re.findall(r'^\s*([a-z0-9_]+_module)\s+\((?:shared|static)\)\s*$',
        engine.run(['/usr/sbin/httpd', '-M']), re.M))
    lines = virtual_hosts(read(APACHE), dump, modules, documentroot, socket,
        include_reader=domain_includes(read))
    inode = live_socket(pid, socket, **(runtime_io or {}))
    return {'format': 1, 'kind': 'go-erp-navigation-php-handler',
        'configuration': {'domain': DOMAIN, 'documentroot': documentroot, 'socket': socket,
            'vhost_lines': lines, 'files': files}, 'live': {'master_pid': pid, 'socket_inode': inode}}


if __name__ == '__main__':
    raise SystemExit('This attestation is only loaded by the pinned rollout coordinator.')
