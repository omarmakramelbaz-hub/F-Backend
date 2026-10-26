"""Serving-handler proofs must bind live processes, source roots and sockets."""
import copy
import importlib.util
from pathlib import Path
import types
import unittest
import stat

SPEC = importlib.util.spec_from_file_location('navigation_php', Path(__file__).resolve().parents[1]
    / 'deployment/go_erp_navigation_php_root.py')
subject = importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(subject)


class HandlerProofTests(unittest.TestCase):
    def setUp(self):
        self.socket = subject.SOCKET_ROOT + 'reviewed-domain.sock'
        self.files = {
            subject.USERDATA: '---\ndocumentroot: /home/fasakha/public_html\nservername: fasakhaninja.com\n'
                'user: fasakha\ngroup: fasakha\nphpversion: ea-php82\n',
            subject.FPM_YAML: '---\n_is_present: 1\n',
            subject.FPM_CONFIG: '[global]\ninclude=/opt/cpanel/ea-php82/root/etc/php-fpm.d/*.conf\n',
            subject.POOL: '[fasakhaninja_com]\nuser="fasakha"\ngroup="fasakha"\nlisten=' + self.socket + '\n',
            subject.APACHE: '<VirtualHost 127.0.0.1:80>\nServerName fasakhaninja.com\n'
                'DocumentRoot "/home/fasakha/public_html"\n<IfModule proxy_fcgi_module>\n'
                '<FilesMatch \\.(phtml|php[0-9]*)$>\nSetHandler "proxy:unix:' + self.socket
                + '|fcgi://fasakhaninja.com"\n</FilesMatch>\n</IfModule>\n</VirtualHost>\n',
        }
        self.dump = 'port 80 namevhost fasakhaninja.com (/etc/apache2/conf/httpd.conf:1)\n'
        self.modules = ' proxy_module (shared)\n proxy_fcgi_module (shared)\n'
        self.exe = subject.FPM_EXE
        self.fd_target = 'socket:[9876]'
        self.unix = 'Num RefCount Protocol Flags Type St Inode Path\n0000: 0002 0000 00010000 0001 01 9876 ' + self.socket + '\n'
        self.service_state = {'ea-php82-php-fpm.service': {'ActiveState': 'active', 'CanReload': 'yes', 'MainPID': '1234'},
            'httpd.service': {'ActiveState': 'active', 'CanReload': 'yes'}, 'lsws.service': {'ActiveState': 'inactive'}}
        self.calls = []
        self.engine = types.SimpleNamespace(run=self.engine_run, read_file=self.read)
        self.io = {'read_text': lambda name: self.unix, 'readlink': self.readlink,
            'listdir': lambda name: ['0','1','7']}

    def engine_run(self, args):
        self.calls.append(args)
        if args[0] == '/usr/bin/systemctl':
            values = self.service_state[args[2]]
            return '\n'.join(name + '=' + values[name] for name in reversed(args[4::2]))
        if args[-1] == 'DUMP_VHOSTS': return self.dump
        if args[-1] == '-M': return self.modules
        raise AssertionError('Unexpected read-only command')

    def read(self, name, maximum, **policy):
        self.assertEqual(policy['uid'], 0)
        self.assertLessEqual(maximum, 2*1024**2)
        return self.files[name].encode(), None

    def readlink(self, name):
        return self.exe if name.endswith('/exe') else self.fd_target if name.endswith('/7') else '/dev/null'

    def verify(self): return subject.verify(self.engine, runtime_io=self.io)

    def test_full_private_binding_requires_only_read_only_commands_and_hashes(self):
        result = self.verify()
        self.assertEqual(result['configuration']['socket'], self.socket)
        self.assertEqual(result['configuration']['documentroot'], str(subject.ROOT))
        self.assertEqual(result['live'], {'master_pid':1234,'socket_inode':'9876'})
        self.assertEqual(set(result['configuration']['files']), {str(name) for name in self.files})
        self.assertTrue(all(len(value) == 64 for value in result['configuration']['files'].values()))
        self.assertFalse(any('reload' in row for row in self.calls))

    def test_other_documentroot_account_or_php_version_cannot_be_adopted(self):
        original = self.files[subject.USERDATA]
        for before, after in [('public_html','another-app'),('ea-php82','ea-php81'),
                ('user: fasakha','user: nobody'), ('servername: fasakhaninja.com','servername: another.example')]:
            self.files[subject.USERDATA] = original.replace(before,after)
            with self.subTest(after=after), self.assertRaises(subject.Stop): self.verify()

    def test_pool_user_socket_or_global_include_changes_fail(self):
        original = copy.deepcopy(self.files)
        for name, before, after in [(subject.POOL,'user="fasakha"','user="nobody"'),
                (subject.POOL,subject.SOCKET_ROOT,'/tmp/'),
                (subject.FPM_CONFIG,'php-fpm.d/*.conf','other/*.conf')]:
            self.files = copy.deepcopy(original); self.files[name] = self.files[name].replace(before,after)
            with self.subTest(after=after), self.assertRaises(subject.Stop): self.verify()

    def test_domain_must_use_exact_pool_and_documentroot_in_every_loaded_vhost(self):
        original = self.files[subject.APACHE]
        for before, after in [(self.socket,self.socket+'other'),('public_html','different'),
                ('ServerName fasakhaninja.com','ServerName other.example'),
                ('</VirtualHost>','SetHandler other\n</VirtualHost>'),
                ('<IfModule proxy_fcgi_module>','<IfModule !proxy_fcgi_module>')]:
            self.files[subject.APACHE] = original.replace(before,after)
            with self.subTest(after=after), self.assertRaises(subject.Stop): self.verify()
        self.files[subject.APACHE] = original
        self.dump += 'port 443 namevhost fasakhaninja.com (/etc/apache2/conf/httpd.conf:500)\n'
        with self.assertRaises(subject.Stop): self.verify()

    def test_unparsed_overrides_or_other_loaded_config_are_never_treated_as_proof(self):
        self.files[subject.APACHE] = self.files[subject.APACHE].replace('</VirtualHost>',
            'IncludeOptional /etc/apache2/unknown/*.conf\n</VirtualHost>')
        with self.assertRaises(subject.Stop): self.verify()

    def test_literal_proxy_exclusions_preserve_the_exact_php_handler_binding(self):
        original = self.files[subject.APACHE]
        for row in ('ProxyPass /.well-known !', 'ProxyPass /.well-known/ !',
                'ProxyPass "/.well-known/acme-challenge/" "!"',
                'ProxyPass "/local-prefix" !', 'ProxyPass / "!"'):
            self.files[subject.APACHE] = original.replace('</VirtualHost>', row+'\n</VirtualHost>')
            with self.subTest(row=row):
                result = self.verify()
                self.assertEqual(result['configuration']['socket'], self.socket)
                self.assertEqual(result['configuration']['vhost_lines'], [1])

    def test_exclusions_inside_positive_loaded_proxy_module_guards_are_supported(self):
        original = self.files[subject.APACHE]
        for guard in ('proxy_module', 'mod_proxy.c'):
            self.files[subject.APACHE] = original.replace('</VirtualHost>',
                '<IfModule '+guard+'>\nProxyPass /.well-known !\n</IfModule>\n</VirtualHost>')
            with self.subTest(guard=guard): self.verify()

    def test_positive_proxy_mappings_and_all_regex_mappings_remain_rejected(self):
        original = self.files[subject.APACHE]
        for row in ('ProxyPass / http://127.0.0.1:8080/', 'ProxyPass / https://another.example/',
                'ProxyPass / unix:'+self.socket+'|fcgi://fasakhaninja.com/',
                'ProxyPassMatch ^/.* !', 'ProxyPassMatch ^/.* http://127.0.0.1:8080/'):
            self.files[subject.APACHE] = original.replace('</VirtualHost>',row+'\n</VirtualHost>')
            with self.subTest(row=row), self.assertRaises(subject.Stop): self.verify()

    def test_proxy_exclusions_reject_unknown_options_interpolation_and_ambiguous_paths(self):
        original = self.files[subject.APACHE]
        for row in ('ProxyPass /local ! nocanon', 'ProxyPass /local ! interpolate',
                'ProxyPass ${LOCAL_PATH} !', 'ProxyPass /${LOCAL_PATH} !',
                'ProxyPass /local?query !', 'ProxyPass /local#fragment !',
                'ProxyPass /local* !', 'ProxyPass /local%2fpath !', 'ProxyPass /local\\path !',
                'ProxyPass //local !', 'ProxyPass /../local !', 'ProxyPass /./local !',
                'ProxyPass "" !', 'ProxyPass !', 'ProxyPass /local "!remote"',
                'ProxyPass "/local\\" !', 'ProxyPass "/local path" !'):
            self.files[subject.APACHE] = original.replace('</VirtualHost>',row+'\n</VirtualHost>')
            with self.subTest(row=row), self.assertRaises(subject.Stop): self.verify()

    def test_proxy_exclusion_does_not_waive_scope_or_module_guard_checks(self):
        original = self.files[subject.APACHE]
        for opening, closing in (('<Location "/local">','</Location>'),
                ('<Directory "/home/fasakha/public_html">','</Directory>'),
                ('<FilesMatch "\\.php$">','</FilesMatch>'),
                ('<IfModule !proxy_module>','</IfModule>'),
                ('<IfModule missing_module>','</IfModule>'),
                ('<IfModule proxy_fcgi_module>','</IfModule>')):
            self.files[subject.APACHE] = original.replace('</VirtualHost>',
                opening+'\nProxyPass /local !\n'+closing+'\n</VirtualHost>')
            with self.subTest(opening=opening), self.assertRaises(subject.Stop): self.verify()

    def test_domain_include_proxy_exclusions_follow_the_same_literal_and_scope_checks(self):
        name = '/etc/apache2/conf.d/userdata/std/2_4/fasakha/fasakhaninja.com/exclude.conf'
        apache = self.files[subject.APACHE].replace('</VirtualHost>',
            'IncludeOptional "'+name+'"\n</VirtualHost>')
        for included, accepted in (('ProxyPass /.well-known !',True),
                ('ProxyPass / http://127.0.0.1:8080/',False),
                ('<Location "/local">\nProxyPass /local !\n</Location>',False)):
            reader = subject.domain_includes(lambda path,limit:included,
                globber=lambda pattern:[name],directory_check=lambda path:True)
            with self.subTest(included=included):
                if accepted:
                    self.assertEqual(subject.virtual_hosts(apache,self.dump,{'proxy_module','proxy_fcgi_module'},
                        str(subject.ROOT),self.socket,include_reader=reader),[1])
                else:
                    with self.assertRaises(subject.Stop):
                        subject.virtual_hosts(apache,self.dump,{'proxy_module','proxy_fcgi_module'},
                            str(subject.ROOT),self.socket,include_reader=reader)

    def test_matching_handler_under_unrelated_directory_or_unknown_conditional_is_rejected(self):
        original = self.files[subject.APACHE]
        for opening,closing in [('<Directory "/unrelated-not-app">','</Directory>'),
                ('<IfVersion < 1.0>','</IfVersion>')]:
            self.files[subject.APACHE] = original.replace('<FilesMatch',opening+'\n<FilesMatch')\
                .replace('</FilesMatch>','</FilesMatch>\n'+closing)
            with self.subTest(opening=opening), self.assertRaises(subject.Stop):self.verify()
        self.dump = self.dump.replace('/etc/apache2/conf/httpd.conf','/tmp/foreign.conf')
        with self.assertRaises(subject.Stop): self.verify()

    def test_disabled_or_conflicting_services_and_missing_module_fail(self):
        for unit, key, value in [('ea-php82-php-fpm.service','CanReload','no'),
                ('httpd.service','ActiveState','inactive'),('lsws.service','ActiveState','active')]:
            old = self.service_state[unit][key]; self.service_state[unit][key] = value
            with self.subTest(unit=unit), self.assertRaises(subject.Stop): self.verify()
            self.service_state[unit][key] = old
        self.modules = 'proxy_module (shared)\n'
        with self.assertRaises(subject.Stop): self.verify()

    def test_live_master_must_be_php82_and_hold_the_actual_listening_socket(self):
        self.exe = '/usr/sbin/php-fpm'
        with self.assertRaises(subject.Stop): self.verify()
        self.exe = subject.FPM_EXE; self.fd_target = 'socket:[9877]'
        with self.assertRaises(subject.Stop): self.verify()
        self.fd_target = 'socket:[9876]'; self.unix = self.unix.replace('00010000','00000000')
        with self.assertRaises(subject.Stop): self.verify()

    def test_duplicate_scalar_policy_or_pool_sections_fail(self):
        self.files[subject.USERDATA] += 'phpversion: ea-php82\n'
        with self.assertRaises(subject.Stop): self.verify()
        self.files[subject.USERDATA] = self.files[subject.USERDATA].rsplit('phpversion:',1)[0]
        self.files[subject.POOL] += '[extra_pool]\n'
        with self.assertRaises(subject.Stop): self.verify()

    def test_nonlistening_stream_rows_with_the_same_path_do_not_hide_the_master_listener(self):
        header, listener = self.unix.splitlines()
        peers = ['0001: 0002 0000 00000000 0001 ' + state + ' ' + str(10000 + index)
            + ' ' + self.socket for index, state in enumerate(('01','02','03','04'))]
        for rows in ([*peers,listener], [listener,*peers]):
            self.unix = '\n'.join([header,*rows]) + '\n'
            with self.subTest(listener_first=rows[0] == listener):
                self.assertEqual(self.verify()['live']['socket_inode'], '9876')

    def test_nonlistening_rows_alone_cannot_prove_a_master_owned_listener(self):
        header = self.unix.splitlines()[0]
        for state in ('01','02','03','04'):
            self.unix = header + '\n0001: 0002 0000 00000000 0001 ' + state + ' 9876 ' + self.socket + '\n'
            with self.subTest(state=state), self.assertRaises(subject.Stop): self.verify()

    def test_master_ownership_of_an_accepted_peer_is_not_listener_ownership(self):
        self.unix += '0001: 0002 0000 00000000 0001 03 9877 ' + self.socket + '\n'
        self.fd_target = 'socket:[9877]'
        with self.assertRaises(subject.Stop): self.verify()

    def test_unknown_same_path_socket_fields_cannot_be_treated_as_normal_peers(self):
        listener = self.unix
        for flags,kind,state,inode in [('00010002','0001','01','9877'),
                ('00000000','0002','03','9877'),('00000000','0001','05','9877'),
                ('00000000','0001','03','not-an-inode')]:
            self.unix = listener + '0001: 0002 0000 ' + flags + ' ' + kind + ' ' + state + ' ' + inode + ' ' + self.socket + '\n'
            with self.subTest(flags=flags,kind=kind,state=state,inode=inode), self.assertRaises(subject.Stop): self.verify()

    def test_distinct_same_path_listeners_remain_ambiguous(self):
        self.unix += '0001: 0002 0000 00010000 0001 01 9877 ' + self.socket + '\n'
        with self.assertRaises(subject.Stop): self.verify()

    def test_root_owned_domain_includes_are_expanded_with_the_same_handler_checks(self):
        name = '/etc/apache2/conf.d/userdata/std/2_4/fasakha/fasakhaninja.com/php.conf'
        handler = 'SetHandler "proxy:unix:' + self.socket + '|fcgi://fasakhaninja.com"'
        apache = self.files[subject.APACHE].replace(handler, 'IncludeOptional "' + name + '"')
        reader = subject.domain_includes(lambda path, limit: handler,
            globber=lambda pattern:[name], directory_check=lambda path:True)
        self.assertEqual(subject.virtual_hosts(apache,self.dump,{'proxy_module','proxy_fcgi_module'},
            str(subject.ROOT),self.socket,include_reader=reader),[1])
        reader = subject.domain_includes(lambda path, limit: 'SetHandler other',
            globber=lambda pattern:[name], directory_check=lambda path:True)
        with self.assertRaises(subject.Stop):
            subject.virtual_hosts(apache,self.dump,{'proxy_module','proxy_fcgi_module'},
                str(subject.ROOT),self.socket,include_reader=reader)

    def test_optional_missing_or_verified_empty_wildcard_is_the_only_empty_include(self):
        folder = '/etc/apache2/conf.d/userdata/ssl/2_4/fasakha/fasakhaninja.com/'
        reader = subject.domain_includes(lambda *args: self.fail('No file should be read'),
            globber=lambda pattern:[],directory_check=lambda path:True)
        self.assertEqual(reader(folder+'missing.conf',True),[])
        self.assertEqual(reader(folder+'*.conf',False),[])
        with self.assertRaises(subject.Stop):reader(folder+'missing.conf',False)
        for pattern in ('/tmp/anything.conf',folder+'../other.conf',folder+'child/*.php'):
            with self.subTest(pattern=pattern), self.assertRaises(subject.Stop):reader(pattern,True)

    def test_recursive_includes_depth_cycles_file_and_byte_budgets_fail_closed(self):
        folder = '/etc/apache2/conf.d/userdata/std/2_4/fasakha/fasakhaninja.com/'
        recursive = lambda name, optional:[(name,'Include '+name)]
        with self.assertRaises(subject.Stop):subject.expand_includes(['Include '+folder+'a.conf'],recursive)
        def chain(name, optional):
            number = int(Path(name).stem)
            return [(name,'Include '+folder+str(number+1)+'.conf')]
        with self.assertRaises(subject.Stop):subject.expand_includes(['Include '+folder+'0.conf'],chain)
        reader = subject.domain_includes(lambda *args:'ok', globber=lambda pattern:[folder+str(n)+'.conf' for n in range(33)],
            directory_check=lambda path:True)
        with self.assertRaises(subject.Stop):reader(folder+'*.conf',True)
        reader = subject.domain_includes(lambda *args:'a'*(256*1024),
            globber=lambda pattern:[folder+str(n)+'.conf' for n in range(9)],directory_check=lambda path:True)
        with self.assertRaises(subject.Stop):reader(folder+'*.conf',True)

    def test_include_directory_prefix_rejects_symlink_or_nonroot_or_writable_ancestor(self):
        good = types.SimpleNamespace(st_mode=stat.S_IFDIR|0o755,st_uid=0)
        for bad in (types.SimpleNamespace(st_mode=stat.S_IFLNK|0o755,st_uid=0),
                types.SimpleNamespace(st_mode=stat.S_IFDIR|0o755,st_uid=1000),
                types.SimpleNamespace(st_mode=stat.S_IFDIR|0o777,st_uid=0)):
            def check(path):return bad if str(path)=='/etc/apache2' else good
            with self.assertRaises(subject.Stop):subject.secure_existing_prefix('/etc/apache2/conf.d',check)


if __name__ == '__main__': unittest.main()
