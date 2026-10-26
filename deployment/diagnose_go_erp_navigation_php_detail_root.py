#!/usr/bin/env python3
"""Bounded, read-only diagnostics for the exact retained navigation rollout."""
import hashlib, inspect, json, os, re, shlex, socket, stat, subprocess
from urllib.parse import urlsplit
from pathlib import Path
assert os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524'
repo = Path('/var/tmp/go-erp-navigation.PbfeXkoR/release.git')
for p in (*reversed(repo.parents), repo):
    s = p.lstat()
    assert stat.S_ISDIR(s.st_mode) and not p.is_symlink() and s.st_uid == 0
    assert not s.st_mode & 0o022 or p == Path('/var/tmp') and s.st_mode & stat.S_ISVTX
assert repo.resolve() == repo
assert not repo.parent.lstat().st_mode & 0o077
rev = 'e2ffe00c792f0c8c39c8cf017b983393ebe62c2a'
env = {'PATH':'/usr/bin:/bin','LANG':'C.UTF-8','GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null','GIT_CONFIG_SYSTEM':'/dev/null','GIT_NO_REPLACE_OBJECTS':'1','GIT_NO_LAZY_FETCH':'1','GIT_TERMINAL_PROMPT':'0','GIT_OPTIONAL_LOCKS':'0'}
r = subprocess.run(['/usr/bin/git','--git-dir='+str(repo),'-c','core.hooksPath=/dev/null','show',rev+':deployment/go_erp_navigation_php_root.py'],env=env,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=30)
assert r.returncode == 0 and len(r.stdout) < 128*1024
assert hashlib.sha256(r.stdout).hexdigest() == '0fa57cb0d078186c1a156bcb5b50d385a3f0e2918628f7e8e173414994f94bea'
n = {'__name__':'go_erp_private_diagnostic','__file__':'pinned_php_handler.py'}
exec(compile(r.stdout,n['__file__'],'exec'),n)
out = {'format':1,'kind':'go-erp-php-handler-detail-diagnostic','helper_revision':rev,'helper_sha256':hashlib.sha256(r.stdout).hexdigest(),'read_only':True,'database_read':False}

def scope_shape(containers, guards):
    known = {'ifmodule','filesmatch','directory','location','locationmatch','files','proxy','if','ifdefine','ifversion'}
    modules = {'proxy_module','mod_proxy.c','proxy_fcgi_module','mod_proxy_fcgi.c'}
    return {'containers':[x if x in known else 'OTHER' for x in containers[:32]],
        'guards':[x if x in modules else 'NEGATED' if x.startswith('!') else 'OTHER' for x in guards[:32]]}

def proxy_shape(row, containers=(), guards=()):
    """Classify routing only; never return arbitrary config tokens or URI text."""
    facts = scope_shape(containers, guards)
    facts['literal_exclusion_accepted'] = n['literal_proxy_exclusion'](row, containers, guards)
    facts['comment_marker_present'] = '#' in row
    try:
        parts = shlex.split(row, comments=False, posix=True)
    except ValueError:
        facts['tokenization'] = 'INVALID'; return facts
    facts['argument_count'] = len(parts)-1
    facts['directive'] = 'ProxyPass' if parts and parts[0].lower() == 'proxypass' else 'ProxyPassMatch' if parts and parts[0].lower() == 'proxypassmatch' else 'OTHER'
    source = parts[1] if len(parts)>1 else ''
    literal = bool(re.fullmatch(r'/[A-Za-z0-9._~/-]*',source)) and '//' not in source and all(p not in ('.','..') for p in source.split('/'))
    facts['source_kind'] = 'ROOT' if source == '/' else 'WELL_KNOWN' if source == '/.well-known' or source.startswith('/.well-known/') else 'CPANEL_PROXY_PREFIX' if source.startswith('/___proxy_subdomain_') else 'LITERAL_OTHER' if literal else 'NONLITERAL_OR_ABSENT'
    destination = parts[2] if len(parts)>2 else ''
    facts['destination_kind'] = 'EXCLUSION' if destination == '!' else 'UNIX' if destination.startswith('unix:') else 'ABSENT' if not destination else 'OTHER'
    if destination.startswith(('http://','https://','fcgi://','ajp://','ws://','wss://')):
        try:
            parsed = urlsplit(destination)
            host = parsed.hostname
            facts['destination_kind'] = parsed.scheme.upper()
            facts['destination_host_kind'] = 'LOOPBACK' if host in ('127.0.0.1','localhost','::1') else 'THIS_DOMAIN' if host == n['DOMAIN'] else 'OTHER'
            facts['destination_credentials_present'] = parsed.username is not None or parsed.password is not None
            facts['destination_query_present'] = bool(parsed.query)
            facts['destination_fragment_present'] = bool(parsed.fragment)
            facts['destination_path_kind'] = 'ROOT' if parsed.path in ('','/') else 'OTHER'
            if facts['destination_host_kind'] == 'LOOPBACK':
                facts['loopback_port'] = parsed.port
        except ValueError:
            facts['destination_parse'] = 'INVALID'
    facts['trailing_argument_count'] = max(0,len(parts)-3)
    return facts

def socket_snapshot(pid, pool):
    """Use one bounded proc snapshot for the facts and the unchanged verifier."""
    try:
        exe = os.readlink('/proc/'+str(pid)+'/exe')
        exe_kind = 'EXPECTED' if exe == n['FPM_EXE'] else 'EXPECTED_DELETED' if exe == n['FPM_EXE']+' (deleted)' else 'OTHER'
    except OSError:
        exe = ''; exe_kind = 'UNREADABLE'
    with open('/proc/net/unix',encoding='utf8') as stream:
        raw = stream.read(2*1024**2+1)
    assert len(raw) <= 2*1024**2
    folder = '/proc/'+str(pid)+'/fd'
    names = os.listdir(folder)
    assert len(names) <= 8192
    targets = {}; disappeared = 0
    for name in names:
        if not re.fullmatch(r'[0-9]+',name): continue
        try: targets[name] = os.readlink(folder+'/'+name)
        except FileNotFoundError: disappeared += 1
    target_set = set(targets.values())
    rows = []; count = 0; listeners = set()
    for row in raw.splitlines()[1:]:
        parts = row.split()
        if len(parts) != 8 or parts[7] != pool: continue
        count += 1
        valid_inode = bool(re.fullmatch(r'[1-9][0-9]*',parts[6]))
        held = valid_inode and 'socket:['+parts[6]+']' in target_set
        if valid_inode and parts[3:6] == ['00010000','0001','01']: listeners.add(parts[6])
        if len(rows)<32:
            rows.append({'flags':parts[3] if re.fullmatch(r'[0-9A-Fa-f]{8}',parts[3]) else 'OTHER',
                'type':parts[4] if re.fullmatch(r'[0-9A-Fa-f]{4}',parts[4]) else 'OTHER',
                'state':parts[5] if re.fullmatch(r'[0-9A-Fa-f]{2}',parts[5]) else 'OTHER',
                'valid_inode':valid_inode,'master_holds_inode':bool(held)})
    out['socket_facts'] = {'exe_kind':exe_kind,'matching_row_count':count,'rows_truncated':count>32,
        'rows':rows,'exact_listener_count':len(listeners),'fd_count':len(names),'fd_disappeared':disappeared,
        'master_holds_listener':any('socket:['+i+']' in target_set for i in listeners)}
    def readlink(path):
        if path == '/proc/'+str(pid)+'/exe': return exe
        assert path.startswith(folder+'/')
        name = path[len(folder)+1:]
        if name not in targets: raise FileNotFoundError
        return targets[name]
    return n['live_socket'](pid,pool,read_text=lambda path:raw,readlink=readlink,listdir=lambda path:names)

phase = ''
original_need = n['need']
def need(value, code='PHP_HANDLER_REVIEW_REQUIRED'):
    if not value:
        f = inspect.currentframe().f_back; v = f.f_locals
        d = {'function':f.f_code.co_name,'line':f.f_lineno}
        d.update(scope_shape(v.get('containers',()),v.get('guards',())))
        if isinstance(v.get('row'),str):
            token = v['row'].strip().split(None,1)[0] if v['row'].strip() else ''
            d['directive'] = token if re.fullmatch(r'[</!A-Za-z_>-]{1,80}',token) else 'OTHER'
            if token.lower() in ('proxypass','proxypassmatch'):
                d['proxy'] = proxy_shape(v['row'],v.get('containers',()),v.get('guards',()))
        if isinstance(v.get('handlers'),list):
            d['handlers'] = [{'pool_socket':bool(v.get('socket')) and ('proxy:unix:'+v['socket']+'|') in x,'domain_backend':('|fcgi://'+n['DOMAIN']) in x,'localhost_backend':'|fcgi://localhost' in x} for x in v['handlers'][:8]]
        if 'references' in v: d['reference_count'] = len(v['references'])
        if 'matches' in v: d['filesmatch_expected'] = v['matches'] in [[r'\.(phtml|php[0-9]*)$'],[r'\.php$']]
        if isinstance(v.get('directives'),list):
            d['binding_directives'] = [{'name':k,'expected':value == (n['DOMAIN'] if k == 'servername' else v.get('documentroot'))} for k,value in v['directives'][:16]]
        if isinstance(v.get('pattern'),str): d['include_pattern_sha256'] = hashlib.sha256(v['pattern'].encode()).hexdigest()
        out.setdefault('failures',{})[phase] = d
    return original_need(value,code)
n['need'] = need
def dump_shapes(value): return {'recognized':len(re.findall(r'(?:namevhost|default server)\s+fasakhaninja\.com\s',value)),'single_vhost':len(re.findall(r'^\s*\S+:\d+\s+fasakhaninja\.com\s+\(',value,re.M))}
files = {}; allowed = {n[k] for k in ('USERDATA','FPM_YAML','POOL','FPM_CONFIG','APACHE')}
class Engine:
    def read_file(self,name,maximum,**policy):
        p = Path(name); assert p in allowed or re.fullmatch(r'/etc/apache2/conf.d/userdata/(std|ssl)/2_4/fasakha/fasakhaninja\.com/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.conf',str(p))
        assert p.resolve() == p
        s = p.lstat(); out.setdefault('file_checks',{})[next((k for k in ('USERDATA','FPM_YAML','POOL','FPM_CONFIG','APACHE') if n[k]==p),'DOMAIN_INCLUDE')] = {'root_owned':s.st_uid==0,'regular':stat.S_ISREG(s.st_mode),'mode':oct(stat.S_IMODE(s.st_mode)),'bounded':s.st_size<=maximum}
        assert stat.S_ISREG(s.st_mode) and s.st_uid == 0 and stat.S_IMODE(s.st_mode) in (0o600,0o640,0o644) and s.st_size <= maximum
        fd = os.open(p,os.O_RDONLY|os.O_NOFOLLOW)
        with os.fdopen(fd,'rb') as stream:
            a = os.fstat(stream.fileno()); raw = stream.read(maximum+1); b = os.fstat(stream.fileno())
        assert (s.st_dev,s.st_ino,s.st_size,s.st_mtime_ns,s.st_ctime_ns) == (a.st_dev,a.st_ino,a.st_size,a.st_mtime_ns,a.st_ctime_ns) == (b.st_dev,b.st_ino,b.st_size,b.st_mtime_ns,b.st_ctime_ns) and len(raw) == s.st_size
        files[str(p)] = raw.decode('utf8'); return raw,s
    def run(self,args):
        assert args[0] == '/usr/bin/systemctl' and args[1:2] == ['show'] and args[2] in ('ea-php82-php-fpm.service','httpd.service','lsws.service') and all(x in ('-p','ActiveState','CanReload','MainPID') for x in args[3:]) or args in (['/usr/sbin/httpd','-t','-D','DUMP_VHOSTS'],['/usr/sbin/httpd','-M'])
        r = subprocess.run(args,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30,env={'PATH':'/usr/bin:/bin','LANG':'C.UTF-8'})
        key = 'httpd_dump' if args[-1]=='DUMP_VHOSTS' else 'httpd_modules' if args[-1]=='-M' else args[2]
        facts = {'stdout_bytes':len(r.stdout),'stderr_bytes':len(r.stderr),'original_stdout_limit_ok':len(r.stdout)<=8192}
        if key=='httpd_dump': facts['stderr_shapes'] = dump_shapes(r.stderr.decode('utf8',errors='replace'))
        out.setdefault('command_io',{})[key] = facts
        assert r.returncode == 0 and len(r.stdout) < 2*1024**2
        return r.stdout.decode('utf8').strip()
e = Engine()
def check(name,work):
    global phase
    phase = name
    try: result = work(); out[name] = {'ok':True}; return result
    except BaseException as err:
        tb = err.__traceback__; frames = []
        while tb:
            if tb.tb_frame.f_code.co_filename in ('pinned_php_handler.py','<stdin>'): frames.append({'function':tb.tb_frame.f_code.co_name,'line':tb.tb_lineno})
            tb = tb.tb_next
        out[name] = {'ok':False,'type':type(err).__name__,'frames':frames[-5:]}; return None
check('original_verifier',lambda:n['verify'](e))
u = check('userdata',lambda:n['fields'](e.read_file(n['USERDATA'],2*1024**2)[0].decode(),{'documentroot','servername','user','group','phpversion'},':')) or {}
out['userdata_fields'] = {k:{'present':k in u,'expected':u.get(k)==v} for k,v in {'documentroot':str(n['ROOT']),'servername':n['DOMAIN'],'user':n['USER'],'group':n['USER'],'phpversion':'ea-php82'}.items()}
out['documentroot_allowed'] = u.get('documentroot') in (str(n['ROOT']),str(n['ROOT']/'public'))
out['userdata_phpversion'] = u.get('phpversion') if u.get('phpversion') in ('ea-php82','ea-php81','ea-php83','inherit') else 'ABSENT' if 'phpversion' not in u else 'OTHER'
pv = check('pool_fields',lambda:n['fields'](e.read_file(n['POOL'],2*1024**2)[0].decode(),{'listen'},'=')) or {}; listen = pv.get('listen','')
out['pool_listen_kind'] = 'UNIX_EXPECTED' if listen.startswith(n['SOCKET_ROOT']) else 'UNIX_OTHER' if listen.startswith('/') else 'TCP_LOOPBACK' if re.fullmatch(r'(?:127\.0\.0\.1|localhost|\[::1\]):[0-9]+',listen) else 'ABSENT' if not listen else 'OTHER'
pool = check('pool',lambda:n['pool_binding'](e.read_file(n['POOL'],2*1024**2)[0].decode(),u.get('documentroot')))
config = check('fpm_config',lambda:n['fields'](e.read_file(n['FPM_CONFIG'],2*1024**2)[0].decode(),{'include'},'=')) or {}
out['fpm_include_expected'] = config.get('include') == str(n['POOL'].parent/'*.conf')
pid = check('fpm_service',lambda:n['service'](e,'ea-php82-php-fpm.service',active='active',reloadable='yes',pid=True))
check('apache_service',lambda:n['service'](e,'httpd.service',active='active',reloadable='yes'))
check('litespeed_service',lambda:n['service'](e,'lsws.service',active='inactive'))
if pool and u.get('documentroot') in (str(n['ROOT']),str(n['ROOT']/'public')):
    dump = check('vhost_dump',lambda:e.run(['/usr/sbin/httpd','-t','-D','DUMP_VHOSTS']))
    modules = check('loaded_modules',lambda:e.run(['/usr/sbin/httpd','-M']))
    if dump is not None and modules is not None:
        out['dump_shapes'] = dump_shapes(dump)
        mods = set(re.findall(r'^\s*([a-z0-9_]+_module)\s+\((?:shared|static)\)\s*$',modules,re.M))
        out['proxy_modules'] = {k:k in mods for k in ('proxy_module','proxy_fcgi_module')}
        check('vhost_binding',lambda:n['virtual_hosts'](e.read_file(n['APACHE'],2*1024**2)[0].decode(),dump,mods,u['documentroot'],pool,include_reader=n['domain_includes'](lambda p,m:e.read_file(p,m)[0].decode())))
        def inventory():
            raw = e.read_file(n['APACHE'],2*1024**2)[0].decode()
            references = set()
            for row in dump.splitlines():
                if re.search(r'\b(?:namevhost|default server)\s+'+re.escape(n['DOMAIN'])+r'\s',row):
                    match = re.search(r'\((/[^():]+):(\d+)\)\s*$',row)
                    assert match and match[1] == str(n['APACHE'])
                    references.add(int(match[2]))
            assert 0<len(references)<=32
            rows = raw.splitlines(); results = []; count = 0
            for ordinal,line in enumerate(sorted(references),1):
                assert 1<=line<=len(rows) and re.fullmatch(r'\s*<VirtualHost\s+[^>]+>\s*',rows[line-1],re.I)
                end = next((i for i in range(line,len(rows)) if re.fullmatch(r'\s*</VirtualHost>\s*',rows[i],re.I)),None)
                assert end is not None
                block = n['expand_includes'](rows[line:end],n['domain_includes'](lambda p,m:e.read_file(p,m)[0].decode()))
                containers = []; guards = []
                for row in block:
                    row = row.strip()
                    tag = re.fullmatch(r'<(/?)([A-Za-z]+)(?:\s+.*)?>',row)
                    if tag:
                        name = tag[2].lower()
                        if tag[1]:
                            if containers and containers[-1] == name: containers.pop()
                            if name == 'ifmodule' and guards: guards.pop()
                        else:
                            containers.append(name)
                            if name == 'ifmodule': guards.append(row[len('<IfModule'): -1].strip())
                    if re.match(r'(?i)^ProxyPass(?:Match)?\b',row):
                        count += 1
                        if len(results)<32:
                            results.append({'vhost_ordinal':ordinal,**proxy_shape(row,containers,guards)})
            out['proxy_inventory'] = {'reference_count':len(references),'count':count,'truncated':count>32,'rows':results}
        check('proxy_inventory_check',inventory)
if pool and pid: check('live_socket',lambda:socket_snapshot(pid,pool))
print('GO_ERP_PHP_HANDLER_DETAIL_DIAG '+json.dumps(out,sort_keys=True,separators=(',',':')))
