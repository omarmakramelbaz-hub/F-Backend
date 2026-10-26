python3 -I -B <<'PY'
import hashlib, inspect, json, os, re, socket, stat, subprocess
from pathlib import Path
assert os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524'
repo = Path('/var/tmp/go-erp-navigation.p9rB9rMa/release.git')
for p in (*reversed(repo.parents), repo):
    s = p.lstat()
    assert stat.S_ISDIR(s.st_mode) and not p.is_symlink() and s.st_uid == 0
    assert not s.st_mode & 0o022 or p == Path('/var/tmp') and s.st_mode & stat.S_ISVTX
assert repo.resolve() == repo
rev = '328182c57515f723f3d781a07434e86bbe03927d'
env = {'PATH':'/usr/bin:/bin','LANG':'C.UTF-8','GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null','GIT_CONFIG_SYSTEM':'/dev/null','GIT_NO_REPLACE_OBJECTS':'1','GIT_NO_LAZY_FETCH':'1','GIT_TERMINAL_PROMPT':'0','GIT_OPTIONAL_LOCKS':'0'}
r = subprocess.run(['/usr/bin/git','--git-dir='+str(repo),'-c','core.hooksPath=/dev/null','show',rev+':deployment/go_erp_navigation_php_root.py'],env=env,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=30)
assert r.returncode == 0 and len(r.stdout) < 128*1024
assert hashlib.sha256(r.stdout).hexdigest() == '4af2807b4f587c75e0a814ea54cc17a35faaf99efd50fc396ed6e5916be315b0'
n = {'__name__':'go_erp_private_diagnostic','__file__':'pinned_php_handler.py'}
exec(compile(r.stdout,n['__file__'],'exec'),n)
out = {'format':1,'kind':'go-erp-php-handler-diagnostic','read_only':True,'database_read':False}
phase = ''
original_need = n['need']
def need(value, code='PHP_HANDLER_REVIEW_REQUIRED'):
    if not value:
        f = inspect.currentframe().f_back; v = f.f_locals
        d = {'function':f.f_code.co_name,'line':f.f_lineno}
        for k in ('containers','guards'):
            if k in v: d[k] = [x if re.fullmatch(r'[!A-Za-z0-9_.-]{1,80}',str(x)) else 'OTHER' for x in v[k]][:32]
        if isinstance(v.get('row'),str):
            token = v['row'].strip().split(None,1)[0] if v['row'].strip() else ''
            d['directive'] = token if re.fullmatch(r'[</!A-Za-z_>-]{1,80}',token) else 'OTHER'
        if isinstance(v.get('handlers'),list):
            d['handlers'] = [{'pool_socket':bool(v.get('socket')) and ('proxy:unix:'+v['socket']+'|') in x,'domain_backend':('|fcgi://'+n['DOMAIN']) in x,'localhost_backend':'|fcgi://localhost' in x} for x in v['handlers'][:8]]
        if 'references' in v: d['reference_count'] = len(v['references'])
        if 'matches' in v: d['filesmatch_expected'] = v['matches'] in [[r'\.(phtml|php[0-9]*)$'],[r'\.php$']]
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
if pool and pid: check('live_socket',lambda:n['live_socket'](pid,pool))
print('GO_ERP_PHP_HANDLER_DIAG '+json.dumps(out,sort_keys=True,separators=(',',':')))
PY
