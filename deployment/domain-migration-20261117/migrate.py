#!/usr/bin/env python3
"""Guarded canonical website migration for the production inventory of 2026-11-17.

Does not deploy repository snapshots, alter the DB, submit payments, remove the
old TLS host, change email identity, or release mobile/desktop applications.
"""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import signal
import socket
import stat
import subprocess
import sys
import tempfile
from urllib.parse import urlsplit

ROOT = Path('/home/fasakha/public_html')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
NEW = 'https://faskhansta.com'
IP = '187.127.92.124'
BEGIN = b'# BEGIN Faskhansta canonical domain v1\n'
END = b'# END Faskhansta canonical domain v1\n'
LINK_FILES = {
 'app/Services/Dashboard/FasakhanstaAiKnowledge.php': '99729479f31c7bbc8abf363ea047718d27f9ccde206a5f816ac0f5decdd6a38c',
 'app/Services/Dashboard/FasakhanstaMessageTrainingFacts.php': '230a28c139d9602b270bb8855a3ea6b4600174710fcd1d0d588c8de90b141596',
 'app/Services/Dashboard/PhoneMapProvider.php': '532516e0e00852f576b1a13a0fa397bd004a38600db44642b9b5bb61b647c62f',
 'app/Services/Dashboard/WhatsAppOrderAiProvider.php': '63514134590586d7d1e35be1316dd31f5d013dace51870a51db784fa823a5a02',
 'app/Services/Dashboard/WhatsAppOrderWorkflow.php': 'ef89837d4eafd4212e9d1bb37d155b93780e1a707e820f5248e85e055bd12e20',
 'app/Services/Site/StorefrontSeo.php': 'a6ca7bd5f2e4ca44dc792c733a60f7b7a3bc1699e08c4f3ec3b7f0fcd38929de',
 'app/Services/Site/WebsiteMetaPurchase.php': '507dfd07b587763118d5196b7c662f205b337e8e08437f385dd45029bbd71e46',
 'app/Support/WhatsAppOrderDialogue.php': '5224913452b383f6c58c4be543974a2cc56360a0537192c9b086825ddc552786',
 'resources/views/site/motion/app.blade.php': '17266e33c9f08faf3d1ddf7b57747aaf863fc71618af0509c05ef82dd12027b4',
 'resources/views/site/motion/products.blade.php': 'a187a601346ce412e02dafff25f50bba45a716cb64a35551e82ea5f74f5efc74',
 'public/links.html': '5a759f36590f915c7df4d3f21b4d3c29f22d79697aef68f7bdfba41c9c83832e',
 'public/robots.txt': '95593b9eb5391634b89b9fed8291683ad15e0ab658f83692be808d67de8fe620',
}
BASELINES = dict(LINK_FILES, **{
 '.htaccess': 'b4e0d9cc55670e43cbaf9a21d2f6923ee4b6918caf98a601fe03d25b25dd3336',
 'app/Providers/AppServiceProvider.php': 'd337d931fc2d7645a1dc2b77f785df6ed0c3c06301441cdfcfdfa0aaa06e4e54',
})
GUARDS = {
 'public/.htaccess': '42493ebc7bad9b95a90363cae9d46ca9934f2d5b4177088d7a08371d5a2623c7',
 'config/app.php': 'fee456188bf8171ef985035ebe78a82de16dfd35602a162dcb540d6197c26e42',
 'config/session.php': '0e988bcfe8cd3a6cb6a95623873a5b7016bf5f028c73b3c13bf67f0f03f8d37e',
 'config/services.php': 'f438d7524fb7bee163cc5076baa75fe44a15de4d14e16836afd858aec8c1b807',
 'config/mail.php': 'fe704e64a93230c4e04818008418e2e6b6c8d8a00ccaa61bdd0c7e7d91da6966',
 'app/Http/Controllers/Payment/PaymobController.php': 'de966706defacd95d07ffe34938f65f6f69819dab7a73bc1edbf021792b4d634',
 'app/Services/GoServices/Payments.php': '6ae48dcb39d74e73c29bb7a67fd8bc8b74ae2ff25aadc697eb52db7c884b03fc',
 'routes/web.php': '4ff8df138736b1b3383400fe754894bde0099e7964216d5d783bb093dd9df8a4',
 'routes/api.php': '7090d76d1e0d255e7b8a2ba7dcf4177e0330973ec56fecdfd0e6b58da9aad872',
}
OLD_HOST = re.compile(rb'(?i)(?<![a-z0-9_.@-])(?:www\.)?fasakhaninja\.com(?![a-z0-9_.-])')
OLD_URL = re.compile(rb'(?i)(?:https?:)?(?:/|\\/){2}(?:www\.)?fasakhaninja\.com(?=[/:?\#\s\"\x27<>\\]|$)')


class Stop(Exception):
    pass


def digest(data):
    return hashlib.sha256(data).hexdigest()


def demand(condition, code):
    if not condition:
        raise Stop(code)


def regular(root, name):
    demand(name in set(BASELINES) | set(GUARDS) | {'.env'}, 'UNEXPECTED_PATH')
    path = root / name
    demand(root.resolve() == root, 'ROOT_IS_SYMLINK')
    for parent in path.parents:
        if parent == root:
            break
        demand(not parent.is_symlink(), 'SYMLINK_PARENT: ' + name)
    info = path.lstat()
    demand(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_size < 4*1024*1024, 'UNSAFE_FILE: ' + name)
    return path, info


def env_values(data):
    output = {}
    for line in data.splitlines():
        match = re.match(rb'^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*)$', line)
        if match:
            key, value = match.groups()
            if key in [b'APP_URL',b'ASSET_URL',b'SESSION_DOMAIN',b'SESSION_SECURE_COOKIE']:
                demand(key not in output, 'DUPLICATE_ENV_SETTING')
            output[key] = value
    return output


def change_env(data):
    values = env_values(data)
    demand(values.get(b'APP_URL', b'').strip(b' \t\"\x27').rstrip(b'/') == b'https://fasakhaninja.com', 'APP_URL_CHANGED_SINCE_AUDIT')
    # The inventory has no ASSET_URL or SESSION_DOMAIN override. Never infer a
    # custom CDN or cookie migration from an unexpected later environment.
    demand(not any(k in values for k in [b'ASSET_URL', b'SESSION_DOMAIN', b'SESSION_SECURE_COOKIE']), 'ENV_CHANGED_SINCE_AUDIT')
    ending = b'\r\n' if b'\r\n' in data else b'\n'
    data, count = re.subn(rb'(?m)^[ \t]*(?:export[ \t]+)?APP_URL[ \t]*=[^\r\n]*', b'APP_URL=' + NEW.encode(), data)
    demand(count == 1, 'APP_URL_NOT_UNIQUE')
    data = data.rstrip(b'\r\n') + ending + b'ASSET_URL=' + NEW.encode() + ending + b'SESSION_DOMAIN=null' + ending + b'SESSION_SECURE_COOKIE=true' + ending
    after = env_values(data)
    demand(all(after.get(k) == v for k, v in values.items() if k != b'APP_URL'), 'UNRELATED_ENV_CHANGED')
    return data


def canonical_provider(data):
    demand(b'FASKHANSTA_CANONICAL_URL_V1' not in data, 'PROVIDER_ALREADY_PATCHED')
    pattern = rb'(public\s+function\s+boot\s*\(\s*\)\s*(?::\s*void\s*)?\{)'
    insertion = b'''
        // FASKHANSTA_CANONICAL_URL_V1: generate site, asset and payment links on the canonical origin.
        if (config('app.url') === 'https://faskhansta.com') {
            \\Illuminate\\Support\\Facades\\URL::forceRootUrl('https://faskhansta.com');
            \\Illuminate\\Support\\Facades\\URL::forceScheme('https');
        }
'''
    result, count = re.subn(pattern, lambda m: m.group(1) + insertion, data)
    demand(count == 1, 'PROVIDER_BOOT_PATTERN_CHANGED')
    return result


def redirect_block(status):
    demand(status in [302, 301], 'INVALID_REDIRECT_STATUS')
    # Never externally redirect API authentication, payment callbacks, signed
    # URLs, OAuth returns or non-idempotent requests from installed old clients.
    # Match THE_REQUEST, not a rewritten /public/ path, preserving escaped URLs.
    return BEGIN + (r'''<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTP_HOST} ^(?:(?:www\.)?fasakhaninja\.com|www\.faskhansta\.com)(?::[0-9]+)?$ [NC]
RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$
RewriteCond %{REQUEST_URI} !^/(?:public/)?(?:api|\.well-known|payment|payments|paymob|pament|wallet|auth|oauth|pusher)(?:/|$) [NC]
RewriteCond %{QUERY_STRING} !(^|&)(signature|hmac|code|state)= [NC]
RewriteCond %{THE_REQUEST} \s(/[^\s?]*)(?:\?[^\s]*)?\s
RewriteRule ^ https://faskhansta.com%1 [R=STATUS,L,NE]
</IfModule>
'''.replace('STATUS', str(status))).encode() + END


def plan(root):
    demand(not (root/'bootstrap/cache/config.php').exists(), 'CONFIG_CACHE_CHANGED_SINCE_AUDIT')
    for name, expected in GUARDS.items():
        path, _ = regular(root, name)
        demand(digest(path.read_bytes()) == expected, 'GUARD_CHANGED: ' + name)
    changes = []
    for name, expected in BASELINES.items():
        path, info = regular(root, name)
        original = path.read_bytes()
        demand(digest(original) == expected, 'SOURCE_CHANGED: ' + name)
        if name == '.htaccess':
            demand(not original.startswith(b'\xef\xbb\xbf'), 'HTACCESS_BOM_REQUIRES_REVIEW')
            desired = redirect_block(302) + original
        elif name == 'app/Providers/AppServiceProvider.php':
            desired = canonical_provider(original)
        else:
            desired = OLD_HOST.sub(b'faskhansta.com', original)
            demand(desired != original, 'NO_WEB_DOMAIN_IN_REPORTED_FILE: ' + name)
        changes.append((name, original, desired, info))
    path, info = regular(root, '.env')
    original = path.read_bytes()
    changes.append(('.env', original, change_env(original), info))
    return changes


def command(args, code, timeout=90, cwd=None):
    try:
        result = subprocess.run(args, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout)
    except subprocess.TimeoutExpired:
        raise Stop(code + '_TIMEOUT')
    demand(result.returncode == 0, code)
    return result.stdout


def php_run(code):
    output = command(['runuser', '-u', 'fasakha', '--', PHP, '-r', code], 'PHP_PROBE_FAILED', cwd=str(ROOT))
    try:
        return json.loads(output)
    except ValueError:
        raise Stop('PHP_PROBE_OUTPUT_INVALID')


def runtime_probe():
    # These outputs are booleans only, never data or credentials. Bootstrap the
    # existing application; no order, payment or database migration is invoked.
    return php_run(r'''
error_reporting(0); ini_set('display_errors','0');
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$origin='https://faskhansta.com';
$flags=[
 'app_url'=>config('app.url')===$origin,
 'asset_url'=>config('app.asset_url')===$origin,
 'session_host_only'=>config('session.domain')===null,
 'session_secure'=>(bool)config('session.secure'),
 'generated_url'=>url('/downloadApp')===$origin.'/downloadApp',
 'generated_asset'=>asset('domain-migration-probe.css')===$origin.'/domain-migration-probe.css',
];
foreach(['go-services.paymob-webhook','go-services.payment-return'] as $name) {
 $flags[$name]=Illuminate\Support\Facades\Route::has($name) && parse_url(route($name),PHP_URL_HOST)==='faskhansta.com';
}
echo json_encode($flags);
''')


def artisan(action):
    command(['runuser','-u','fasakha','--',PHP,'artisan',action,'--no-interaction'], 'ARTISAN_' + action.upper().replace(':','_'), cwd=str(ROOT))


def reload_runtime():
    artisan('view:clear')
    # No config cache was present in the reviewed inventory. Do not introduce
    # one: some existing application modules still call env() at runtime.
    command(['systemctl','reload','ea-php82-php-fpm.service'], 'PHP_FPM_RELOAD_FAILED')


def http(host, path='/', method='GET', headers=None):
    with tempfile.TemporaryDirectory(prefix='faskhansta-http-') as directory:
        body = Path(directory)/'body'
        args = ['curl','--noproxy','*','--silent','--show-error','--connect-timeout','10','--max-time','45',
                '--resolve',host+':443:'+IP,'--output',str(body),'--write-out','%{http_code}\n%{redirect_url}',
                '--request',method,'--header','Accept: text/html','--header','Cache-Control: no-cache']
        for item in headers or []:
            args += ['--header', item]
        args += ['https://' + host + path]
        output = command(args,'HTTPS_PROBE_FAILED',timeout=55).decode().split('\n',1)
        return int(output[0]), output[1], body.read_bytes()


def baseline_http():
    required = ['/', '/products', '/products/feseekh', '/links.html', '/robots.txt']
    for path in required:
        code, _, _ = http('faskhansta.com',path)
        demand(code == 200, 'BASELINE_HTTP_NOT_200: ' + path)
    code, _, _ = http('www.faskhansta.com')
    demand(code == 200, 'BASELINE_WWW_NOT_200')
    code, _, _ = http('fasakhaninja.com')
    demand(code == 200, 'BASELINE_OLD_NOT_200')
    api = {}
    for host in ['fasakhaninja.com','faskhansta.com']:
        code, redirect, _ = http(host, '/api/setting', headers=['Accept: application/json'])
        demand(code == 200 and not redirect, 'BASELINE_API_NOT_200')
        api[host] = code
    return required, api


def smoke(required, api, redirect_status):
    for path in required:
        code, _, body = http('faskhansta.com',path)
        demand(code == 200, 'NEW_HTTP_NOT_200: ' + path)
        demand(not OLD_URL.search(body), 'OLD_URL_IN_RENDERED_PAGE: ' + path)
    for host in ['fasakhaninja.com','www.faskhansta.com']:
        for path in ['/', '/products?domain_migration_probe=1', '/links.html', '/downloadApp']:
            code, redirect, _ = http(host,path)
            demand(code == redirect_status and redirect == NEW+path, 'REDIRECT_FAILED: ' + host + path)
    for host, expected in api.items():
        code, redirect, _ = http(host,'/api/setting',headers=['Accept: application/json'])
        demand(code == expected and not redirect, 'API_COMPATIBILITY_FAILED')
    # Download destinations are external app stores, not the retired domain.
    for agent, destination in [('Mozilla/5.0 (Linux; Android 14)', 'play.google.com'), ('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)', 'apps.apple.com')]:
        code, redirect, _ = http('faskhansta.com','/downloadApp',headers=['User-Agent: '+agent])
        demand(code in [301,302,303,307,308] and urlsplit(redirect).hostname == destination, 'APP_DOWNLOAD_REDIRECT_FAILED')
    flags = runtime_probe()
    demand(flags and all(value is True for value in flags.values()), 'RUNTIME_CONFIG_CHECK_FAILED')
    print('RUNTIME_CHECKS ' + json.dumps(flags, sort_keys=True), flush=True)


def attrs(info):
    return [info.st_uid,info.st_gid,stat.S_IMODE(info.st_mode)]


def replace(path, data, expected_attrs):
    fd, name = tempfile.mkstemp(prefix='.domain-migration-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        # Preserve xattrs/SELinux context and ownership of the exact live file.
        shutil.copystat(str(path),name,follow_symlinks=False)
        os.chown(name,expected_attrs[0],expected_attrs[1])
        os.chmod(name,expected_attrs[2])
        os.replace(name,str(path))
    finally:
        if os.path.exists(name):
            os.unlink(name)


def unchanged(root, change, after=False):
    name, before, target, info = change
    path, now = regular(root,name)
    demand(attrs(now)==attrs(info) and path.read_bytes()==(target if after else before), 'CONCURRENT_CHANGE: '+name)
    return path


def lint(changes):
    with tempfile.TemporaryDirectory(prefix='faskhansta-lint-') as directory:
        for i, (name, _, target, _) in enumerate(changes):
            if name.endswith('.php') and not name.endswith('.blade.php'):
                path=Path(directory)/(str(i)+'.php')
                path.write_bytes(target)
                command([PHP,'-l',str(path)],'PHP_LINT_FAILED: '+name)


def save_backup(changes):
    parent=Path('/var/backups')
    demand(parent.is_dir() and not parent.is_symlink(), 'BACKUP_PARENT_MISSING')
    backup=Path(tempfile.mkdtemp(prefix='faskhansta-canonical.',dir=str(parent)))
    metadata={}
    for name,before,target,info in changes:
        path=backup/name
        path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
        path.write_bytes(before)
        path.chmod(0o600)
        metadata[name]={'before':digest(before),'after':digest(target),'attrs':attrs(info)}
    (backup/'manifest.json').write_text(json.dumps(metadata,sort_keys=True))
    print('Backup: '+str(backup),flush=True)
    return backup


def write_manifest(backup, changes):
    metadata={name:{'before':digest(before),'after':digest(target),'attrs':attrs(info)} for name,before,target,info in changes}
    (backup/'manifest.json').write_text(json.dumps(metadata,sort_keys=True))


def restore(root, changes):
    failed=[]
    for change in reversed(changes):
        try:
            path=unchanged(root,change,after=True)
            replace(path,change[1],attrs(change[3]))
        except (OSError,Stop):
            failed.append(change[0])
    demand(not failed,'ROLLBACK_CONFLICT: '+','.join(failed))


def rollback(backup):
    demand(backup.parent==Path('/var/backups') and backup.name.startswith('faskhansta-canonical.') and backup.resolve()==backup, 'INVALID_BACKUP_PATH')
    info=backup.lstat()
    demand(stat.S_ISDIR(info.st_mode) and info.st_uid==0 and stat.S_IMODE(info.st_mode)==0o700,'UNSAFE_BACKUP')
    manifest=backup/'manifest.json'
    demand(not manifest.is_symlink() and manifest.stat().st_uid==0,'UNSAFE_MANIFEST')
    records=json.loads(manifest.read_text())
    demand(set(records)==set(BASELINES)|{'.env'},'BACKUP_FILES_MISMATCH')
    changes=[]
    for name,entry in records.items():
        saved=backup/name
        try:
            saved.resolve().relative_to(backup)
        except ValueError:
            raise Stop('UNSAFE_BACKUP_FILE')
        demand(saved.is_file() and not saved.is_symlink(),'UNSAFE_BACKUP_FILE')
        data=saved.read_bytes()
        path,info=regular(ROOT,name)
        current=path.read_bytes()
        demand(digest(data)==entry['before'] and attrs(info)==entry['attrs'],'BACKUP_CONTENT_MISMATCH')
        if current==data:
            continue
        demand(digest(current)==entry['after'],'ROLLBACK_SOURCE_CHANGED: '+name)
        changes.append((name,data,current,info))
    restore(ROOT,changes)
    reload_runtime()
    print('CANONICAL_MIGRATION_ROLLED_BACK')


def worker_count():
    count=0
    for path in Path('/proc').glob('[0-9]*/cmdline'):
        try:
            value=path.read_bytes().replace(b'\x00',b' ')
            if b'artisan' in value and re.search(rb'queue:(work|listen)|artisan horizon',value):
                count+=1
        except OSError:
            pass
    return count


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    group=parser.add_mutually_exclusive_group()
    group.add_argument('--apply',action='store_true')
    group.add_argument('--rollback',type=Path)
    args=parser.parse_args()
    os.umask(0o077)
    demand(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','RUN_ON_SRV1836524_AS_ROOT')
    demand(ROOT.resolve()==ROOT and ROOT.is_dir(),'APPLICATION_ROOT_MISMATCH')
    demand(pwd.getpwnam('fasakha').pw_uid==ROOT.stat().st_uid,'APPLICATION_OWNER_MISMATCH')
    lock=open('/run/lock/faskhansta-domain.lock','a')
    fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    command(['systemctl','is-active','--quiet','ea-php82-php-fpm.service'],'PHP_FPM_UNIT_NOT_ACTIVE')
    command(['httpd','-t'],'APACHE_CONFIGURATION_INVALID')
    if args.rollback:
        rollback(args.rollback)
        return
    changes=plan(ROOT)
    lint(changes)
    required,api=baseline_http()
    print('PREFLIGHT_PASSED: '+str(len(changes))+' narrowly scoped file updates',flush=True)
    if not args.apply:
        print('CHECK_ONLY_NO_APPLICATION_CHANGES')
        return
    backup=save_backup(changes)
    written=[]
    def interrupted(signum, frame):
        raise Stop('INTERRUPTED')
    signal.signal(signal.SIGINT,interrupted)
    signal.signal(signal.SIGTERM,interrupted)
    try:
        for change in changes:
            unchanged(ROOT,change)
        # Publish generated canonical links first; redirect browser requests last.
        for change in sorted(changes,key=lambda c:c[0]=='.htaccess'):
            path=unchanged(ROOT,change)
            replace(path,change[2],attrs(change[3]))
            written.append(change)
        reload_runtime()
        smoke(required,api,302)
        print('TEMPORARY_REDIRECT_AND_HTTPS_CHECKS_PASSED',flush=True)
        for i,change in enumerate(written):
            if change[0]=='.htaccess':
                path=unchanged(ROOT,change,after=True)
                target=redirect_block(301)+change[1]
                replace(path,target,attrs(change[3]))
                written[i]=(change[0],change[1],target,change[3])
        write_manifest(backup,written)
        smoke(required,api,301)
    except BaseException:
        print('MIGRATION_FAILED_RESTORING_PREVIOUS_FILES',flush=True)
        restore(ROOT,written)
        reload_runtime()
        print('PREVIOUS_APPLICATION_FILES_RESTORED; backup='+str(backup),flush=True)
        raise
    print('FASKHANSTA_CANONICAL_WEBSITE_READY')
    print(NEW)
    print('Old browser links redirect to the new domain. Legacy API/callback/signed URLs remain compatible.')
    print('Email sender unchanged. No database rewrite or app-store release performed.')
    print('Long-running queue processes detected: '+str(worker_count())+'; worker supervision/reload remains to be checked.')
    print('Authenticated login, complete order/payment, provider settings and released clients still need verification.')
    print('Rollback: python3 /root/faskhansta-migrate.py --rollback '+str(backup))


if __name__=='__main__':
    try:
        main()
    except Stop as exc:
        print('STOP: '+str(exc),file=sys.stderr)
        sys.exit(1)
    except Exception as exc:
        # Never print arbitrary PHP output, source fragments or environment values.
        print('STOP: '+type(exc).__name__,file=sys.stderr)
        sys.exit(1)
