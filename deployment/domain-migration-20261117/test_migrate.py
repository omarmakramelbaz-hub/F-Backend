import contextlib
import hashlib
import http.client
import importlib.util
import json
import os
from pathlib import Path
import pwd
import grp
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
from unittest.mock import patch

spec=importlib.util.spec_from_file_location('migration',Path(__file__).with_name('migrate.py'))
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class TransformationTests(unittest.TestCase):
    def test_environment_preserves_credentials_and_email_identity(self):
        original=b'APP_URL="https://fasakhaninja.com"\r\nAPP_KEY=KEEP_SECRET\r\nMAIL_FROM_ADDRESS=mail@fasakhaninja.com\r\nDB_PASSWORD="a#b,c"\r\n'
        desired=m.change_env(original)
        self.assertIn(b'APP_URL=https://faskhansta.com\r\n',desired)
        self.assertIn(b'ASSET_URL=https://faskhansta.com\r\n',desired)
        self.assertIn(b'SESSION_DOMAIN=null\r\n',desired)
        self.assertTrue(desired.endswith(b'SESSION_SECURE_COOKIE=true\r\n'))
        for line in original.splitlines()[1:]:
            self.assertIn(line,desired)

    def test_unexpected_environment_stops_before_any_write(self):
        for value in [b'APP_URL=https://other.test',b'APP_URL=https://fasakhaninja.com\nASSET_URL=https://cdn.test',b'APP_URL=https://fasakhaninja.com\nAPP_URL=https://fasakhaninja.com',b'APP_URL=https://fasakhaninja.com\nSESSION_DOMAIN=.fasakhaninja.com']:
            with self.assertRaises(m.Stop):m.change_env(value)

    def test_domain_changes_preserve_email_subdomains_and_unrelated_hosts(self):
        source=b'https://fasakhaninja.com/a?b=1 https://www.fasakhaninja.com/a \\"https:\\/\\/fasakhaninja.com/a\\" //fasakhaninja.com/img.webp user@fasakhaninja.com https://test.fasakhaninja.com xfasakhaninja.com fasakhaninja.com.evil'
        result=m.OLD_HOST.sub(b'faskhansta.com',source)
        self.assertIn(b'https://faskhansta.com/a?b=1',result)
        self.assertNotIn(b'www.fasakhaninja.com',result)
        for unchanged in [b'user@fasakhaninja.com',b'https://test.fasakhaninja.com',b'xfasakhaninja.com',b'fasakhaninja.com.evil']:
            self.assertIn(unchanged,result)

    def test_rendered_link_detector_ignores_email_and_external_hosts(self):
        for url in [b'https://fasakhaninja.com/a',b'//www.fasakhaninja.com/a',b'https:\\/\\/fasakhaninja.com/a']:
            self.assertIsNotNone(m.OLD_URL.search(url))
        for text in [b'mail@fasakhaninja.com',b'https://fasakhaninja.com.evil/a',b'https://test.fasakhaninja.com/a']:
            self.assertIsNone(m.OLD_URL.search(text))

    def test_provider_insertion_does_not_replace_other_boot_code(self):
        before=b'<?php class A {public function boot() {\n keep_existing();\n}}'
        after=m.canonical_provider(before)
        self.assertIn(b'keep_existing();',after)
        self.assertEqual(after.count(b'forceRootUrl'),1)
        self.assertEqual(after.count(b'forceScheme'),1)
        with self.assertRaises(m.Stop):m.canonical_provider(after)
        with self.assertRaises(m.Stop):m.canonical_provider(b'<?php function boot($argument) {}')

    @unittest.skipUnless(shutil.which('php'),'PHP CLI not installed locally')
    def test_provider_php_syntax_and_url_behavior(self):
        source=b'<?php namespace App\\Providers; class Example {public function boot() {}}'
        target=m.canonical_provider(source)
        harness=r'''<?php
namespace Illuminate\Support\Facades {class URL {public static $root; public static $scheme; public static function forceRootUrl($x) {self::$root=$x;} public static function forceScheme($x) {self::$scheme=$x;}}}
namespace {function config($key) {return 'https://faskhansta.com';}}
'''
        with tempfile.TemporaryDirectory() as directory:
            p=Path(directory)/'provider.php';p.write_bytes(target)
            subprocess.run(['php','-l',str(p)],check=True,capture_output=True)
            h=Path(directory)/'harness.php'
            h.write_text(harness+'\nnamespace {require '+json.dumps(str(p))+'; (new \\App\\Providers\\Example())->boot(); if (\\Illuminate\\Support\\Facades\\URL::$root !== "https://faskhansta.com" || \\Illuminate\\Support\\Facades\\URL::$scheme !== "https") {exit(1);} }')
            subprocess.run(['php',str(h)],check=True,capture_output=True)


class PublicationTests(unittest.TestCase):
    def fixture(self,root):
        p=root/'public/robots.txt';p.parent.mkdir();p.write_bytes(b'Sitemap: https://fasakhaninja.com/sitemap.xml\n')
        p.chmod(0o640)
        return ('public/robots.txt',p.read_bytes(),p.read_bytes().replace(b'fasakhaninja.com',b'faskhansta.com'),p.stat())

    def test_atomic_write_and_rollback_preserve_owner_and_permissions(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);change=self.fixture(root)
            path=m.unchanged(root,change)
            m.replace(path,change[2],m.attrs(change[3]))
            self.assertEqual(path.read_bytes(),change[2])
            self.assertEqual(m.attrs(path.stat()),m.attrs(change[3]))
            m.restore(root,[change])
            self.assertEqual(path.read_bytes(),change[1])
            self.assertEqual(m.attrs(path.stat()),m.attrs(change[3]))

    def test_concurrent_edit_is_not_overwritten_during_rollback(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);change=self.fixture(root)
            path=root/change[0];path.write_bytes(b'SOMEONE_ELSE_EDITED')
            with self.assertRaises(m.Stop):m.restore(root,[change])
            self.assertEqual(path.read_bytes(),b'SOMEONE_ELSE_EDITED')

    def test_symlink_and_hardlink_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);(root/'public').mkdir();other=root/'other';other.write_bytes(b'X')
            path=root/'public/robots.txt';path.symlink_to(other)
            with self.assertRaises(m.Stop):m.regular(root,'public/robots.txt')
            path.unlink();os.link(other,path)
            with self.assertRaises(m.Stop):m.regular(root,'public/robots.txt')


@unittest.skipUnless(shutil.which('apache2') or shutil.which('httpd'),'Apache binary not installed locally')
class ApacheIntegrationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory=tempfile.TemporaryDirectory(prefix='faskhansta-apache-')
        cls.root=Path(cls.directory.name);cls.root.chmod(0o755)
        cls.site=cls.root/'site';cls.site.mkdir(mode=0o755)
        for name in ['index.html','page.txt','signed.txt','api/probe.txt','api/auth/callback','payment/return','public/file.txt','.well-known/acme-challenge/check']:
            file=cls.site/name;file.parent.mkdir(parents=True,exist_ok=True);file.write_text('ok');file.chmod(0o644)
        access=cls.site/'.htaccess'
        access.write_bytes(m.redirect_block(301)+b'RewriteRule ^doc$ public/file.txt [L]\n');access.chmod(0o644)
        with socket.socket() as free:
            free.bind(('127.0.0.1',0));cls.port=free.getsockname()[1]
        binary=shutil.which('apache2') or shutil.which('httpd')
        modules=Path('/usr/lib/apache2/modules')
        if not modules.is_dir():raise unittest.SkipTest('Debian/Ubuntu module layout required')
        user='www-data' if os.getuid()==0 else pwd.getpwuid(os.getuid()).pw_name
        group='www-data' if os.getuid()==0 else grp.getgrgid(os.getgid()).gr_name
        content='\n'.join('LoadModule '+name+'_module '+str(modules/('mod_'+name+'.so')) for name in ['mpm_event','authz_core','authz_host','dir','mime','rewrite'])
        content+='\n'+f'''ServerRoot "{cls.root}"
PidFile "{cls.root}/httpd.pid"
Listen 127.0.0.1:{cls.port}
ServerName localhost
User {user}
Group {group}
ErrorLog "{cls.root}/error.log"
LogLevel warn
TypesConfig /etc/mime.types
DirectoryIndex index.html
DocumentRoot "{cls.site}"
<Directory "{cls.site}">
AllowOverride All
Require all granted
Options FollowSymLinks
</Directory>
'''
        config=cls.root/'httpd.conf';config.write_text(content)
        subprocess.run([binary,'-t','-f',str(config)],check=True,capture_output=True)
        cls.process=subprocess.Popen([binary,'-f',str(config),'-DFOREGROUND'],stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',cls.port),timeout=.1):break
            except OSError:time.sleep(.03)
        else:raise AssertionError('Apache failed to start: '+cls.process.stderr.read().decode())

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate();cls.process.wait(timeout=10);cls.process.stderr.close();cls.directory.cleanup()

    def request(self,host,path,method='GET'):
        conn=http.client.HTTPConnection('127.0.0.1',self.port,timeout=5)
        conn.request(method,path,headers={'Host':host})
        response=conn.getresponse();body=response.read();result=(response.status,response.getheader('Location'),body);conn.close();return result

    def test_old_and_www_browsers_redirect_with_path_and_query(self):
        for host in ['fasakhaninja.com','www.fasakhaninja.com','www.faskhansta.com','fasakhaninja.com:443']:
            for path in ['/','/page.txt?q=1&x=2','/doc','/%D9%81%D8%B3%D9%8A%D8%AE?x=a%20b','/test%23name']:
                with self.subTest(host=host,path=path):
                    status,location,_=self.request(host,path)
                    self.assertEqual(status,301)
                    self.assertEqual(location,m.NEW+path)

    def test_api_callback_signed_and_certificate_paths_are_not_redirected(self):
        for path in ['/api/probe.txt','/api/auth/callback?code=example&state=example','/payment/return?hmac=example','/signed.txt?signature=example','/.well-known/acme-challenge/check']:
            with self.subTest(path=path):
                status,location,_=self.request('fasakhaninja.com',path)
                self.assertEqual(status,200);self.assertIsNone(location)

    def test_post_and_options_are_not_redirected(self):
        for method in ['POST','OPTIONS']:
            status,location,_=self.request('fasakhaninja.com','/page.txt',method)
            self.assertNotIn(status,[301,302,307,308]);self.assertIsNone(location)

    def test_canonical_and_unrelated_hosts_do_not_redirect(self):
        for host in ['faskhansta.com','test.fasakhaninja.com','fasakhaninja.com.attacker.test']:
            status,location,_=self.request(host,'/page.txt')
            self.assertEqual(status,200);self.assertIsNone(location)
        status,location,body=self.request('faskhansta.com','/doc')
        self.assertEqual((status,location,body),(200,None,b'ok'))

    def test_head_redirect(self):
        status,location,body=self.request('fasakhaninja.com','/page.txt?x=1','HEAD')
        self.assertEqual((status,location,body),(301,m.NEW+'/page.txt?x=1',b''))


if __name__=='__main__':unittest.main()
