#!/usr/bin/env python3
"""One pinned navigation correction to an already prepared, unpublished rollout.

The installed watcher, seed, configuration, durable state and database are never
rewritten. Its existing source-drift handling allocates the next preview. A new
held identity replaces only an exactly recognized unauthorized old hold.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import sys
import types
import uuid

ROOT = Path('/home/fasakha/public_html')
WORK = Path('/var/lib/go-erp-live-updates')
CORE = 'd3cef0a0f823d1f10814ff74ce0f4badf5c29670'
SEED = 'cd35ee36d77bc35593cac3627a33307b71309325'
OLD_CANDIDATE = 'dceb59ad489bbba6b6534a4da784f6d26c82b2ff'
OLD_VERSION = '0.4.4-preview.36'
NAV = 'public/dashboard/js/dashboard-navigation.js'
BEFORE_NAV = '611d6b79a243a4fdce16be1fca8a2343ffdd70c1'
AFTER_NAV = '5817024fb309d86ccaae2c79ad21391aa8507ecb'
OLD_SOURCE = {'format': 1, 'files': 1342, 'framework': '8.83.29', 'sha256': '13bf1542e2118724272545f70f8eb2bd113fc1fe475bbc9a378135528f6824ea'}
NEW_SOURCE = {'format': 1, 'files': 1346, 'sha256': '41172badb00910203115a45b26c695d47757ee1cc4ac26d07ba556a7f657ea63', 'framework': '8.83.29'}
OLD_INVENTORY = '0068ecaeb994a7c7bd3916eeaa137a1c33e0f2ddf3f1e5b3807a35db61d56005'
NEW_INVENTORY = '1480586d09f7b87ac2528f74b14a5694c631d31c6ddc5c32eb8dc750c5550a9c'
PRIOR_BLOB = '602ba86d2fd59221e046b58fa9c197fa4544973e'
PREFIX = 'confirmation-navigation-successor-'
BEFORE_SOURCE = {'format': 1, 'files': 1346, 'sha256': '5825ec04b927fcafc42e04bd9ee4705a2e1862bb62be700a116502eacad60a1e', 'framework': '8.83.29'}
PAYMENT_RELEASE = '31d4020a9195904e1578bd6207f5dc805aa1541d'
DELIVERY_RELEASE = '59d4fd2466137ee5c31a9f4701d3f5bc024a6ea3'
PAYMENT_PINS = {'app/Services/Dashboard/OrderBoardService.php': ('d2c8c69de733b131aa27f36e02d6b4b0b990b6b2', '6cb2330647bd701291be94fe5549f2d1bc000015'), 'app/Services/Dashboard/PosServiceTicket.php': ('3549b9fa0df2dd97a630b20bb9753df4d4178bf4', 'b1600ecb7c1f5ec6a3d8f90b6c391ccca8f5babe'), 'public/dashboard/js/phone-orders.js': ('5923ef6feccda16bd1d759e9d9d307a96f8c6480', '319cbc52a36f0f73ad69f3285e4e7d52b5e7fa69'), 'public/dashboard/css/phone-delivery-refresh.css': ('bd9f95fb6d8a28f382708ca6e6a9b36f12d62392', '181359e8b0e4088f544eede725306fcbf3f7e9e7'), 'app/Http/Controllers/CheckoutController.php': ('00efa1050e81c210bd921f665afb1cdf5d89889d', 'ec76900a10c11c702659eff3199d4b751dce5343'), 'app/Http/Controllers/Payment/PaymobController.php': ('13e48a6675303c651ad72ee5d79c88b6e56d520c', '9bd38c2a69debfea91d31d2a7f3179ab0fd631b4'), 'app/Http/Controllers/Payment/RestaurantPaymentController.php': (None, 'efc57fdc0ea3f3173080a2282402e1c51305ef3e'), 'app/Services/Payments/RestaurantOrderPayment.php': (None, '1699f40405d04abe92ae5350b57d26f0a90a8b36'), 'app/Services/Payments/RestaurantPaymentEffects.php': (None, '23264d1573363b722b87dab064e53da1e14df6de'), 'app/Console/Commands/ReconcileRestaurantPayments.php': (None, 'ff7d17d64d8676b89dee567fa434fdecfaf59c8e'), 'app/Console/Kernel.php': ('b6b28cbe6cb1098a1861bd3b4c905c61a0f58ef8', 'a3d44fd2fa9827ee2bae5fe93cffa6a470eac7af'), 'routes/api.php': ('16d776a16436821c5e5815dcbdd807f35cb0353f', 'bc722739c14e22607028de7bb15f59a55a8ab464'), 'resources/views/admin/orders/board_card.blade.php': ('359ddbc50c651da22f7aee10ed9d7a27df26a6a5', '6b723579bd15adc82524bd6056d79feac37aac87'), 'resources/views/admin/orders/board_detail.blade.php': ('ef6c357fcaff06fbc5d125e14be352138214a822', 'e94cb48239828025415861b1c8ef45621049ddbb'), 'resources/lang/ar/order_board.php': ('93e67aab5ae5beea8261b6007c8453496eb08a18', '966b799f864d96f36b17684b6cc03a716dbea7af'), 'resources/lang/en/order_board.php': ('deac8a800e24146897a902ac6a4efe78b5dbdfb5', '9a0a2eb2fdf97a4bf248547b274331b03bfd2d01'), 'public/dashboard/css/order-board.css': ('97b409f020f6ef547956205057f7795955416379', '88093e1439246947cde3a540b1db0d14229a7d67')}
PREPARED_OVERRIDES = {'routes/api.php': 'bc722739c14e22607028de7bb15f59a55a8ab464'}
APPLICATION_BLOB = '313665fe855522fd57bcc5dd6b0caf23ec9b41ab'
PUBLIC_REASONS = frozenset(['GO_ERP_NAV_SUCCESSOR_APPROVED_PAYMENT_FILE_DIFFERS', 'GO_ERP_NAV_SUCCESSOR_CANDIDATE_APPROVED_FILE_INVALID', 'GO_ERP_NAV_SUCCESSOR_CANDIDATE_CODE_INVALID', 'GO_ERP_NAV_SUCCESSOR_CANDIDATE_INVALID', 'GO_ERP_NAV_SUCCESSOR_CANDIDATE_METADATA_INVALID', 'GO_ERP_NAV_SUCCESSOR_CANDIDATE_PACKAGE_INVALID', 'GO_ERP_NAV_SUCCESSOR_CANDIDATE_PARENT_INVALID', 'GO_ERP_NAV_SUCCESSOR_CANDIDATE_VERSION_INVALID', 'GO_ERP_NAV_SUCCESSOR_COMMIT_REQUIRES_REVIEW', 'GO_ERP_NAV_SUCCESSOR_COMPLETION_RECORD_INVALID', 'GO_ERP_NAV_SUCCESSOR_CONTROL_FILE_CHANGED', 'GO_ERP_NAV_SUCCESSOR_CONTROL_PLANE_CHANGED', 'GO_ERP_NAV_SUCCESSOR_COUNTER_INVALID', 'GO_ERP_NAV_SUCCESSOR_DESIRED_CANDIDATE_MISSING', 'GO_ERP_NAV_SUCCESSOR_HELD_OLD_HOLD_CHANGED', 'GO_ERP_NAV_SUCCESSOR_INITIAL_STATE_DIFFERS', 'GO_ERP_NAV_SUCCESSOR_INNER_COMMIT_INVALID', 'GO_ERP_NAV_SUCCESSOR_INSTALLED_UNIT_DIFFERS', 'GO_ERP_NAV_SUCCESSOR_JOURNAL_CONTROLS_DIFFER', 'GO_ERP_NAV_SUCCESSOR_JOURNAL_IDENTITY_INVALID', 'GO_ERP_NAV_SUCCESSOR_MULTIPLE_PENDING_TRANSACTIONS', 'GO_ERP_NAV_SUCCESSOR_NEW_HOLD_CHANGED', 'GO_ERP_NAV_SUCCESSOR_OLD_CANDIDATE_ALREADY_PUBLISHED', 'GO_ERP_NAV_SUCCESSOR_OLD_DESIRED_DIFFERS', 'GO_ERP_NAV_SUCCESSOR_OLD_HOLD_CHANGED', 'GO_ERP_NAV_SUCCESSOR_OLD_HOLD_DIFFERS_OR_AUTHORIZED', 'GO_ERP_NAV_SUCCESSOR_OLD_ROLLOUT_ALREADY_COMPLETED', 'GO_ERP_NAV_SUCCESSOR_PACKAGE_PATH_UNSAFE', 'GO_ERP_NAV_SUCCESSOR_PAYLOAD_ROSTER_INVALID', 'GO_ERP_NAV_SUCCESSOR_PAYMENT_HELPER_IDENTITY_INVALID', 'GO_ERP_NAV_SUCCESSOR_PENDING_HOLD_DIFFERS', 'GO_ERP_NAV_SUCCESSOR_PENDING_TRANSACTION_REQUIRES_REVIEW', 'GO_ERP_NAV_SUCCESSOR_PREPARED_PROGRAM_FILE_DIFFERS', 'GO_ERP_NAV_SUCCESSOR_PREPARE_REQUIRES_REVIEW', 'GO_ERP_NAV_SUCCESSOR_PREVIOUS_NAVIGATION_TRANSACTION_REQUIRES_REVIEW', 'GO_ERP_NAV_SUCCESSOR_PRIOR_IDENTITY_INVALID', 'GO_ERP_NAV_SUCCESSOR_PRIOR_MODULE_PIN_INVALID', 'GO_ERP_NAV_SUCCESSOR_PRIOR_MODULE_UNSAFE', 'GO_ERP_NAV_SUCCESSOR_PUBLICATION_CHANGED', 'GO_ERP_NAV_SUCCESSOR_REMOTE_BRANCH_CHANGED', 'GO_ERP_NAV_SUCCESSOR_REMOTE_BRANCH_INVALID', 'GO_ERP_NAV_SUCCESSOR_ROLLBACK_HOLD_CHANGED', 'GO_ERP_NAV_SUCCESSOR_ROLLBACK_OLD_HOLD_DIFFERS', 'GO_ERP_NAV_SUCCESSOR_ROLLBACK_REQUIRES_REVIEW', 'GO_ERP_NAV_SUCCESSOR_ROOT_AND_HOST_REQUIRED', 'GO_ERP_NAV_SUCCESSOR_SOURCE_UNREVIEWED', 'GO_ERP_NAV_SUCCESSOR_SUCCESSOR_ACCEPTANCE_REQUIRED', 'GO_ERP_NAV_SUCCESSOR_SUCCESSOR_COMMIT_MISSING', 'GO_ERP_NAV_SUCCESSOR_SUCCESSOR_COUNTER_DIFFERS', 'GO_ERP_NAV_SUCCESSOR_SUCCESSOR_DESIRED_DIFFERS', 'GO_ERP_NAV_SUCCESSOR_SUCCESSOR_HOLD_DIFFERS', 'GO_ERP_NAV_SUCCESSOR_SUCCESSOR_LEASE_DIFFERS'])
MAX_STATE = 16 * 1024 * 1024


class Stop(Exception):
    pass


def need(value, reason):
    if not value:
        raise Stop('GO_ERP_NAV_SUCCESSOR_' + reason)


def requires_review(reason,source=None):
    error=Stop('GO_ERP_NAV_SUCCESSOR_'+reason);error.rollback_complete=False
    error.application_contents_changed=True if source==NEW_SOURCE else 'unknown'
    raise error


def digest(data):
    return hashlib.sha256(data).hexdigest()


def canonical(value):
    return (json.dumps(value, sort_keys=True) + '\n').encode()


def load_prior(package):
    path = package / 'upgrade_go_erp_program_watcher_root.py'
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(fd)
        need(stat.S_ISREG(info.st_mode) and (info.st_uid, info.st_gid, info.st_nlink, stat.S_IMODE(info.st_mode))
             == (0, 0, 1, 0o644) and 0 < info.st_size <= 2*1024*1024, 'PRIOR_MODULE_UNSAFE')
        data = os.read(fd, 2*1024*1024 + 1)
        blob = hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
        key = lambda item: (item.st_dev, item.st_ino, item.st_mode, item.st_uid, item.st_gid, item.st_nlink,
                            item.st_size, item.st_mtime_ns, item.st_ctime_ns)
        need(blob == PRIOR_BLOB and key(info) == key(os.fstat(fd)) == key(path.lstat()), 'PRIOR_MODULE_PIN_INVALID')
    finally:
        os.close(fd)
    module = types.ModuleType('pinned_program_navigation_prior'); module.__file__ = str(path)
    sys.modules[module.__name__] = module
    exec(compile(data, str(path), 'exec'), module.__dict__)
    return module


class Context:
    def __init__(self, package):
        self.package = package
        self.prior = p = load_prior(package)
        need((p.CORE, p.NEW_SEED, p.NEW_SOURCE, p.NEW_INVENTORY) == (CORE, SEED, OLD_SOURCE, OLD_INVENTORY), 'PRIOR_IDENTITY_INVALID')
        self.base = b = p.load_pinned(package/p.BASE_MODULE, p.BASE_BLOB)
        self.utility = u = b.load_utility(package/'upgrade_go_erp_capacity_watcher_root.py')
        self.application = p.load_pinned(package/'go_erp_confirmation_nav_application_patch.py', APPLICATION_BLOB)
        need(self.application.PAYMENT_PINS==PAYMENT_PINS and self.application.PAYMENT_BASE_SOURCE==OLD_SOURCE
             and self.application.PAYMENT_SOURCE==BEFORE_SOURCE,'PAYMENT_HELPER_IDENTITY_INVALID')
        self.gate = p.load_pinned(package/p.GATE_MODULE, p.GATE_BLOB)
        directory = u.Directory(package/'payload', 0o700)
        try:
            need(set(os.listdir(directory.fd)) == set(p.RAW_PINS), 'PAYLOAD_ROSTER_INVALID')
            raw = {name:u.read_at(directory,name,u.MAX_FILE,0o600) for name in p.RAW_PINS}
        finally:
            directory.close()
        payloads = p.adapt_payloads(raw, (package/p.GATE_MODULE).read_bytes())
        self.pins = {name:p.blob(data) for name,data in payloads.items()}
        self.runtime = b.RELEASES/u.runtime_name(payloads)
        runtime = u.Directory(self.runtime, 0o755)
        try:
            u.validate_runtime(runtime, self.pins)
            self.publisher = u.module_at(runtime, 'publish_go_erp_update.py', self.pins['publish_go_erp_update.py'])
            self.worker = u.module_at(runtime, 'go_erp_live_updates.py', self.pins['go_erp_live_updates.py'])
        finally:
            runtime.close()
        self.expected = self.gate.program_gate_expected(CORE, NEW_SOURCE, NEW_INVENTORY)
        self.old_expected = self.gate.program_gate_expected(CORE, OLD_SOURCE, OLD_INVENTORY)
        self.application.configure(BEFORE_SOURCE, NEW_SOURCE, {NAV:(BEFORE_NAV, AFTER_NAV)})
        need(set(PREPARED_OVERRIDES)=={'routes/api.php'} and set(self.prior.TARGET_PINS).intersection(PAYMENT_PINS)==set(PREPARED_OVERRIDES)
             and all(PAYMENT_PINS[name][1]==wanted for name,wanted in PREPARED_OVERRIDES.items()),'PAYMENT_HELPER_IDENTITY_INVALID')
        self.owner = pwd.getpwnam('fasakha')

    def controls(self):
        """Read private control-plane slots; never persist or print credentials."""
        b,u = self.base,self.utility
        result = {}
        for key,parent,name,limit,mode in (
            ('config',b.CONFIG.parent,b.CONFIG.name,16384,0o600),
            ('state',WORK/'state','current.json',MAX_STATE,0o600),
            ('unit',b.UNIT.parent,b.UNIT.name,65536,0o644)):
            directory=u.Directory(parent,0o700 if key!='unit' else 0o755)
            try:
                fd=os.open(name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=directory.fd)
                try:
                    info=os.fstat(fd);data=u.read_fd(fd,limit,mode)
                    need(u.exact_key(info)==u.exact_key(os.stat(name,dir_fd=directory.fd,follow_symlinks=False)),'CONTROL_FILE_CHANGED')
                    result[key]=(data,u.exact_key(info))
                finally:os.close(fd)
            finally:directory.close()
        config=b.parse(result['config'][0]);state=b.parse(result['state'][0])
        b.checked_config(config,SEED);b.checked_state(state,SEED)
        b.checkout(WORK/'checkout',SEED,u)
        need(result['unit'][0]==b.unit_bytes(u,self.runtime,ROOT,WORK,b.CONFIG),'INSTALLED_UNIT_DIFFERS')
        u.effective_unit(b.UNIT,b.CONFIG,(self.runtime,))
        # These observations bind bytes and inode metadata but do not retain
        # the configuration's token in a journal or public result.
        observations={key:canonical({'sha256':digest(value[0]),'identity':value[1]}) for key,value in result.items()}
        with self.application.PinnedSourceReader(ROOT,self.owner.pw_uid) as reader:
            for name,wanted in self.prior.TARGET_PINS.items():
                data,_=reader._read(name)
                need(data is not None and self.prior.blob(data)==PREPARED_OVERRIDES.get(name,wanted),'PREPARED_PROGRAM_FILE_DIFFERS')
            protected=[]
            for name,(_,wanted) in PAYMENT_PINS.items():
                data,info=reader._read(name)
                need(data is not None and self.prior.blob(data)==wanted,'APPROVED_PAYMENT_FILE_DIFFERS')
                protected.append({'path':name,'blob':wanted,'identity':u.exact_key(info)})
        observations['payment']=canonical(protected)
        return observations,state

    def publication(self):
        try:return self.publisher.validate_release(self.publisher.read_json(ROOT/'storage/app/desktop-updates/current.json',root_owned=True))
        except FileNotFoundError:return None

    def remote(self):
        ref='refs/heads/codex/go-erp-live-source-20261021'
        data=self.base.git(WORK/'checkout','ls-remote','--exit-code',self.base.REMOTE,ref,maximum=4096)
        match=re.fullmatch(rb'([a-f0-9]{40})\t'+ref.encode()+rb'\n',data)
        need(match is not None,'REMOTE_BRANCH_INVALID')
        return match[1].decode()

    def candidate(self, revision, inventory, minimum):
        b,p=self.base,self.prior
        need(isinstance(revision,str) and p.REV.fullmatch(revision),'CANDIDATE_INVALID')
        b.git(WORK/'checkout','fetch','--quiet','--depth=1','--no-tags','--no-recurse-submodules',b.REMOTE,revision)
        header=b.git(WORK/'checkout','cat-file','commit',revision).split(b'\n\n',1)[0]
        need([line[7:].decode() for line in header.splitlines() if line.startswith(b'parent ')]==[SEED],'CANDIDATE_PARENT_INVALID')
        names=set(b.git(WORK/'checkout','diff','--name-only','--no-renames','-z',SEED,revision,'--').decode().split('\0'))-{''}
        need(names.issubset(set(p.VERSIONS)|(set(PAYMENT_PINS)|{NAV} if inventory==NEW_INVENTORY else set())),'CANDIDATE_CODE_INVALID')
        meta=b.parse(b.git(WORK/'checkout','show',revision+':'+p.VERSIONS[2]))
        number=meta.get('versionSequence');rows=meta.get('inventory')
        need(type(number) is int and minimum<=number<=p.SAFE and isinstance(rows,dict)
             and meta==p.candidate_metadata(SEED,rows,number) and meta['inventorySha256']==inventory,'CANDIDATE_METADATA_INVALID')
        need((revision==OLD_CANDIDATE and number==36) if inventory==OLD_INVENTORY else number>36,'CANDIDATE_VERSION_INVALID')
        if inventory==NEW_INVENTORY:
            for name,wanted in {NAV:AFTER_NAV,**{key:pair[1] for key,pair in PAYMENT_PINS.items()}}.items():
                need(b.git(WORK/'checkout','rev-parse',revision+':'+name).decode().strip()==wanted,'CANDIDATE_APPROVED_FILE_INVALID')
        for path in p.VERSIONS[:2]:
            expected=b.parse(b.git(WORK/'checkout','show',SEED+':'+path));actual=b.parse(b.git(WORK/'checkout','show',revision+':'+path))
            expected['version']=meta['version']
            if path.endswith('package-lock.json'):expected['packages']['']['version']=meta['version']
            need(actual==expected,'CANDIDATE_PACKAGE_INVALID')
        return number

    def state_guard(self,state,initial=False):
        need(type(state.get('counter')) is int and state['counter']>=36,'COUNTER_INVALID')
        public=self.publication();published=state.get('published')
        need(public is None or public.get('source_revision')!=OLD_CANDIDATE,'OLD_CANDIDATE_ALREADY_PUBLISHED')
        need(not isinstance(published,dict) or published.get('commit')!=OLD_CANDIDATE,'OLD_CANDIDATE_ALREADY_PUBLISHED')
        desired=state.get('desired');remote=self.remote()
        need(isinstance(desired,dict),'DESIRED_CANDIDATE_MISSING')
        if desired.get('commit')==OLD_CANDIDATE:
            need(desired.get('inventorySha256')==OLD_INVENTORY and desired.get('version')==OLD_VERSION
                 and desired.get('pushed') is True and remote==state.get('last_remote_sha')==OLD_CANDIDATE,'OLD_DESIRED_DIFFERS')
            self.candidate(OLD_CANDIDATE,OLD_INVENTORY,36)
            if initial:
                need(state['counter']==36 and desired.get('status') in ('waiting-ci','retry-build','blocked')
                     and published is None,'INITIAL_STATE_DIFFERS')
        else:
            need(not initial and desired.get('inventorySha256')==NEW_INVENTORY,'SUCCESSOR_DESIRED_DIFFERS')
            number=self.candidate(desired.get('commit'),NEW_INVENTORY,37)
            need(number<=state['counter'] and desired.get('version')=='0.4.4-preview.'+str(number),'SUCCESSOR_COUNTER_DIFFERS')
            need((desired.get('pushed') is True and remote==state.get('last_remote_sha')==desired['commit'])
                 or (desired.get('pushed') is False and remote==state.get('last_remote_sha')),'SUCCESSOR_LEASE_DIFFERS')
        return public


def hold_value(expected):
    return {'format':1,'kind':'go-erp-program-rollout-hold',**expected,'authorization':None}


def journal_audits(ctx):
    result=[]
    for path in WORK.iterdir():
        if re.fullmatch(PREFIX+r'[a-f0-9]{32}',path.name):
            ctx.gate.program_gate_directory(path)
            if (path/'intent.json').exists():result.append(path)
    return result


def prepared_result(state,**extra):
    return {'format':1,'kind':'go-erp-confirmation-nav-successor-prepared','ok':True,'counter':state['counter'],
        'worker_request':'candidate','publication_held':True,'published':False,'timer_paused':True,
        'database_changed':False,'configuration_changed':False,'state_changed':False,'checkout_changed':False,
        'unit_changed':False,'credentials_disclosed':False,'rollback_complete':True,**extra}


def validate_intent(ctx,path,controls):
    value=ctx.gate.program_gate_json(path/'intent.json')
    expected={'format':1,'kind':'go-erp-navigation-successor-intent','core':CORE,'seed':SEED,
        'old_candidate':OLD_CANDIDATE,'old_hold':hold_value(ctx.old_expected),'new_hold':hold_value(ctx.expected),
        'before_source':BEFORE_SOURCE,'after_source':NEW_SOURCE,'nav_before':BEFORE_NAV,'nav_after':AFTER_NAV,
        'payment_release':PAYMENT_RELEASE,'payment_pins':{name:list(pair) for name,pair in PAYMENT_PINS.items()},
        'manifest_source_chain':[OLD_SOURCE,BEFORE_SOURCE,NEW_SOURCE]}
    need(isinstance(value,dict) and set(value)==set(expected)|{'control_hashes'}
         and all(value.get(key)==item for key,item in expected.items()),'JOURNAL_IDENTITY_INVALID')
    hashes=value['control_hashes']
    need(isinstance(hashes,dict) and set(hashes)=={'config','unit','state','payment'}
         and all(isinstance(item,str) and re.fullmatch(r'[a-f0-9]{64}',item) for item in hashes.values())
         and all(hashes[key]==digest(controls[key]) for key in ('config','unit','payment')),'JOURNAL_CONTROLS_DIFFER')
    return value


def completion_value(recovered=False):
    return {'format':1,'ok':True,'core':CORE,'seed':SEED,'source':NEW_SOURCE,'inventory_sha256':NEW_INVENTORY,
            **({'recovered_commit':True} if recovered else {})}


def inner_commit(ctx,path):
    value=ctx.gate.program_gate_json(path/'application/application-committed.json')
    need(isinstance(value,dict) and set(value)=={'source','database_changed','manifest_refreshed','manifest_preserved_absent'}
         and value['source']==NEW_SOURCE and value['database_changed'] is False
         and type(value['manifest_refreshed']) is bool and type(value['manifest_preserved_absent']) is bool
         and value['manifest_refreshed'] is not value['manifest_preserved_absent'],'INNER_COMMIT_INVALID')
    intent=ctx.gate.program_gate_json(path/'application/application-intent.json')
    need(isinstance(intent,dict) and set(intent)=={'before_source','after_source','manifest_refreshed','manifest_preserved_absent','files','stage'}
         and intent['before_source']==BEFORE_SOURCE and intent['after_source']==NEW_SOURCE
         and intent['manifest_refreshed'] is value['manifest_refreshed']
         and intent['manifest_preserved_absent'] is value['manifest_preserved_absent']
         and isinstance(intent['files'],list),'INNER_COMMIT_INVALID')
    files=intent['files'];expected_names=[NAV]+([ctx.application.MANIFEST] if value['manifest_refreshed'] else [])
    manifest=ctx.application.optional_manifest(ROOT,ctx.owner.pw_uid)[0]
    need(len(files)==len(expected_names) and all(isinstance(row,dict) and set(row)=={'path','before_blob','after_blob'} for row in files)
         and [row['path'] for row in files]==expected_names
         and files[0]=={'path':NAV,'before_blob':BEFORE_NAV,'after_blob':AFTER_NAV}
         and all(isinstance(row[key],str) and re.fullmatch(r'[a-f0-9]{40}',row[key]) for row in files for key in ('before_blob','after_blob'))
         and (manifest is not None) is value['manifest_refreshed']
         and (manifest is None or ctx.application.blob(manifest)==files[-1]['after_blob']),'INNER_COMMIT_INVALID')


def transaction(ctx,lock_fd,verify,controls,state):
    """Hold stays present throughout; source is reversed before restoring old hold."""
    g,u,app=ctx.gate,ctx.utility,ctx.application
    expected_old=hold_value(ctx.old_expected);expected_new=hold_value(ctx.expected)
    need(g.program_gate_json(WORK/'program-rollout-hold.json')==expected_old,'OLD_HOLD_DIFFERS_OR_AUTHORIZED')
    need(not (WORK/('program-rollout-completed-'+CORE+'.json')).exists(),'OLD_ROLLOUT_ALREADY_COMPLETED')
    ctx.state_guard(state,initial=True)
    app.verify_before(ROOT,ctx.package,ctx.owner.pw_uid)
    audit=WORK/(PREFIX+uuid.uuid4().hex);os.mkdir(audit,0o700)
    application_audit=audit/'application';os.mkdir(application_audit,0o700)
    patch=app.ApplicationPatch(ROOT,ctx.package,application_audit,ctx.owner.pw_uid,ctx.owner.pw_gid,lock_fd)
    directory=u.Directory(audit,0o700);work=u.Directory(WORK,0o700)
    switched=False
    def unchanged():
        verify();ctx.prior.assert_quiescent()
        need(ctx.controls()[0]==controls,'CONTROL_PLANE_CHANGED')
        need(ctx.remote()==OLD_CANDIDATE,'REMOTE_BRANCH_CHANGED')
        need(ctx.publication()==publication,'PUBLICATION_CHANGED')
    publication=ctx.publication()
    try:
        u.write_at(directory,'after-hold.json',canonical(expected_new))
        old_bytes=g.program_gate_bytes(WORK/'program-rollout-hold.json')
        old_hold_identity=u.exact_key((WORK/'program-rollout-hold.json').lstat())
        u.write_at(directory,'before-hold.json',old_bytes)
        intent={'format':1,'kind':'go-erp-navigation-successor-intent','core':CORE,'seed':SEED,
            'old_candidate':OLD_CANDIDATE,'old_hold':expected_old,'new_hold':expected_new,
            'before_source':BEFORE_SOURCE,'after_source':NEW_SOURCE,'nav_before':BEFORE_NAV,'nav_after':AFTER_NAV,
            'payment_release':PAYMENT_RELEASE,'payment_pins':{name:list(pair) for name,pair in PAYMENT_PINS.items()},
            'manifest_source_chain':[OLD_SOURCE,BEFORE_SOURCE,NEW_SOURCE],
            'control_hashes':{key:digest(value) for key,value in controls.items()}}
        u.json_at(directory,'intent.json',intent)
        patch.prepare();unchanged()
        need(g.program_gate_bytes(WORK/'program-rollout-hold.json')==old_bytes
             and u.exact_key((WORK/'program-rollout-hold.json').lstat())==old_hold_identity,'OLD_HOLD_CHANGED')
        u.exchange(directory.fd,'after-hold.json',work.fd,'program-rollout-hold.json');switched=True
        os.fsync(directory.fd);os.fsync(work.fd)
        need(g.program_gate_json(WORK/'program-rollout-hold.json')==expected_new,'NEW_HOLD_CHANGED')
        patch.apply();unchanged();patch.verify()
        need(g.program_gate_json(WORK/'program-rollout-hold.json')==expected_new,'NEW_HOLD_CHANGED')
        patch.commit()
        u.json_at(directory,'completed.json',completion_value())
        return prepared_result(state,application_contents_changed=True,audit_directory=str(audit))
    except BaseException as error:
        if patch.committed or (application_audit/'application-committed.json').exists():
            problem=Stop('GO_ERP_NAV_SUCCESSOR_COMMIT_REQUIRES_REVIEW');problem.rollback_complete=False
            problem.application_contents_changed=True;raise problem from None
        try:
            patch.rollback()
            app.verify_before(ROOT,ctx.package,ctx.owner.pw_uid)
            unchanged()
            if switched:
                need(g.program_gate_json(WORK/'program-rollout-hold.json')==expected_new,'ROLLBACK_HOLD_CHANGED')
                need(u.read_at(directory,'after-hold.json',2*1024*1024,0o600)==old_bytes,'HELD_OLD_HOLD_CHANGED')
                u.exchange(directory.fd,'after-hold.json',work.fd,'program-rollout-hold.json')
                os.fsync(directory.fd);os.fsync(work.fd)
            need(g.program_gate_json(WORK/'program-rollout-hold.json')==expected_old,'ROLLBACK_OLD_HOLD_DIFFERS')
            u.json_at(directory,'rolled-back.json',{'format':1,'restored':True,'database_changed':False})
        except BaseException:
            problem=Stop('GO_ERP_NAV_SUCCESSOR_ROLLBACK_REQUIRES_REVIEW');problem.rollback_complete=False
            problem.application_contents_changed='unknown';raise problem from None
        raise error
    finally:
        directory.close();work.close();patch.close()


def execute(package,mode,receipt=None):
    need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','ROOT_AND_HOST_REQUIRED')
    package=Path(package).absolute();need(package.resolve()==package,'PACKAGE_PATH_UNSAFE')
    ctx=Context(package)
    with ctx.prior.held_lock(ctx.utility,WORK) as (lock_fd,verify):
        ctx.prior.assert_quiescent();controls,state=ctx.controls()
        ctx.prior.require_no_pending(ctx.base,ctx.utility,WORK)
        for path in list(WORK.glob('navigation-successor-*')) + list(WORK.glob('payment-navigation-successor-*')):
            ctx.gate.program_gate_directory(path)
            if (path/'intent.json').exists() and not (path/'rolled-back.json').exists():
                requires_review('PREVIOUS_NAVIGATION_TRANSACTION_REQUIRES_REVIEW')
        audits=journal_audits(ctx)
        pending=[path for path in audits if not (path/'completed.json').exists() and not (path/'rolled-back.json').exists()]
        if len(pending)>1:requires_review('MULTIPLE_PENDING_TRANSACTIONS')
        scanner=ctx.application.pinned_scanner(package);source=scanner.digest(scanner.scan(ROOT))
        if source==BEFORE_SOURCE:
            if pending:requires_review('PENDING_TRANSACTION_REQUIRES_REVIEW',source)
            need(mode=='prepare','PREPARE_REQUIRES_REVIEW')
            return transaction(ctx,lock_fd,verify,controls,state)
        if source!=NEW_SOURCE and pending:requires_review('PENDING_TRANSACTION_REQUIRES_REVIEW',source)
        need(source==NEW_SOURCE,'SOURCE_UNREVIEWED')
        ctx.application.verify_current(ROOT,package,ctx.owner.pw_uid,ctx.owner.pw_gid,lock_fd)
        completed=[]
        for path in audits:
            try:validate_intent(ctx,path,controls)
            except BaseException:
                if path in pending:requires_review('PENDING_TRANSACTION_REQUIRES_REVIEW',source)
                raise
            if (path/'completed.json').exists():
                value=ctx.gate.program_gate_json(path/'completed.json')
                need(value in (completion_value(),completion_value(True)),'COMPLETION_RECORD_INVALID')
                inner_commit(ctx,path);completed.append(path)
        if pending:
            try:
                path=pending[0];validate_intent(ctx,path,controls)
                need((path/'application/application-committed.json').exists(),'PENDING_TRANSACTION_REQUIRES_REVIEW')
                inner_commit(ctx,path)
                need(ctx.gate.program_gate_json(WORK/'program-rollout-hold.json')==hold_value(ctx.expected),'PENDING_HOLD_DIFFERS')
                ctx.state_guard(state)
                ctx.gate.program_gate_write(path/'completed.json',completion_value(True))
                completed.append(path)
            except BaseException:requires_review('PENDING_TRANSACTION_REQUIRES_REVIEW',source)
        need(completed,'SUCCESSOR_COMMIT_MISSING')
        if ctx.gate.program_gate_completed(WORK,ctx.expected,state,ctx.publication):
            return {'format':1,'kind':'go-erp-program-already-completed','ok':True,'worker_request':'none',
                'resume_previous_timer':True,'publication_held':False,'published':False,'database_changed':False,'credentials_disclosed':False}
        ctx.state_guard(state)
        hold=ctx.gate.program_gate_json(WORK/'program-rollout-hold.json')
        need(all(hold.get(key)==value for key,value in ctx.expected.items()),'SUCCESSOR_HOLD_DIFFERS')
        if mode=='prepare':return prepared_result(state,already_current=True,application_contents_changed=False)
        need(receipt is not None and state['desired'].get('inventorySha256')==NEW_INVENTORY,'SUCCESSOR_ACCEPTANCE_REQUIRED')
        ctx.gate.program_gate_authorize(WORK,receipt,ctx.expected,state,
            lambda folder,run,desired:ctx.worker.verify_proof(folder,run,desired,ctx.publisher),ctx.publication)
        verify();need(ctx.controls()[0]==controls,'CONTROL_PLANE_CHANGED');ctx.prior.assert_quiescent()
        return {'format':1,'kind':'go-erp-confirmation-nav-successor-publication-authorized','ok':True,
            'worker_request':'authorized-publication','candidate':state['desired']['commit'],
            'publication_held':True,'published':False,'database_changed':False,'credentials_disclosed':False}


def safe_error_details(error,package):
    """Fixed diagnostics from exact reviewed definitions; never stringify an error."""
    import ast
    import subprocess
    known=(Exception,OSError,FileNotFoundError,PermissionError,NotADirectoryError,IsADirectoryError,
        BlockingIOError,TimeoutError,RuntimeError,ValueError,TypeError,KeyError,IndexError,AttributeError,
        AssertionError,ImportError,ModuleNotFoundError,SyntaxError,MemoryError,EOFError,UnicodeError,
        UnicodeDecodeError,json.JSONDecodeError,subprocess.CalledProcessError,subprocess.TimeoutExpired,
        KeyboardInterrupt,SystemExit)
    details={'type':{kind:kind.__name__ for kind in known}.get(type(error),'Exception')}
    reason='GO_ERP_NAV_SUCCESSOR_PRIVATE_FAILURE';cache={}
    pins={'upgrade_go_erp_program_watcher_root.py':(PRIOR_BLOB,''),
        'upgrade_go_erp_preview31_watcher_root.py':('ede9af30ea42ba53a5376d5e1cf634d9b3032f2a',''),
        'upgrade_go_erp_capacity_watcher_root.py':('60df7ec876f9634e0a7dcb4c5a420d0d5ecd9ae9',''),
        'go_erp_confirmation_nav_application_patch.py':(APPLICATION_BLOB,'GO_ERP_PROGRAM_APPLICATION_'),
        'go_erp_program_rollout_gate.py':('f24125324966d271158d6fe96b5472ae6e0ef894','GO_ERP_PROGRAM_GATE_'),
        'refresh_desktop_dashboard_server_manifest.py':('8a6834d46dcc29dc4af05fab7d7d47aac6bd5242','')}
    own=str(Path(__file__).absolute());paths={str(Path(package).absolute()/name):(name,pin,prefix) for name,(pin,prefix) in pins.items()}
    paths[own]=('upgrade_go_erp_confirmation_nav_successor_root.py',None,'GO_ERP_NAV_SUCCESSOR_')
    def definitions(code):
        result={code}
        for child in code.co_consts:
            if isinstance(child,types.CodeType):result.update(definitions(child))
        return result
    def reviewed(path,pin,prefix):
        fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
        try:
            before=os.fstat(fd);key=lambda item:(item.st_dev,item.st_ino,item.st_mode,item.st_uid,item.st_gid,item.st_nlink,item.st_size,item.st_mtime_ns,item.st_ctime_ns)
            if not (stat.S_ISREG(before.st_mode) and (before.st_uid,before.st_gid,before.st_nlink,stat.S_IMODE(before.st_mode))==(0,0,1,0o644) and 0<before.st_size<=2*1024*1024):return None
            data=bytearray()
            while len(data)<=2*1024*1024:
                part=os.read(fd,min(65536,2*1024*1024+1-len(data)))
                if not part:break
                data.extend(part)
            if len(data)!=before.st_size or key(before)!=key(os.fstat(fd)) or key(before)!=key(Path(path).lstat()):return None
            data=bytes(data)
            if pin is not None and hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()!=pin:return None
        finally:os.close(fd)
        allowed=set(PUBLIC_REASONS) if path==own else set()
        for node in ast.walk(ast.parse(data)):
            if not isinstance(node,ast.Call) or not isinstance(node.func,ast.Name):continue
            name=node.func.id;position=1 if name in ('need','program_gate_need') else 0
            if name not in ('need','program_gate_need','Stop','ProgramGateError','requires_review') or len(node.args)<=position:continue
            argument=node.args[position];value=argument.value if isinstance(argument,getattr(ast,'Constant',())) else None
            if value is None and sys.version_info<(3,8) and isinstance(argument,ast.Str):value=argument.s
            head=prefix if name in ('need','program_gate_need','requires_review') else ''
            if type(value) is str and re.fullmatch(r'[A-Z][A-Z0-9_]{0,127}',head+value):allowed.add(head+value)
        return definitions(compile(data,path,'exec')),allowed
    trace=error.__traceback__
    while trace is not None:
        frame=trace.tb_frame;path=frame.f_code.co_filename;row=paths.get(path)
        if row is not None:
            if path not in cache:
                try:cache[path]=reviewed(path,row[1],row[2])
                except BaseException:cache[path]=None
            trusted=cache[path];module=sys.modules.get(frame.f_globals.get('__name__'))
            defining=frame.f_globals is globals() if path==own else module is not None and module.__dict__ is frame.f_globals
            if trusted is not None and defining and frame.f_globals.get('__file__')==path and frame.f_code in trusted[0]:
                details.update(file=row[0],line=trace.tb_lineno,site='raise' if trace.tb_next is None else 'caller')
                classes=(frame.f_globals.get('Stop'),frame.f_globals.get('ProgramGateError'))
                if trace.tb_next is None and type(error) in classes and type(error).__module__==frame.f_globals.get('__name__'):
                    details['type']='ProgramGateError' if type(error) is classes[1] else 'Stop'
                    arguments=error.args
                    if len(arguments)==1 and type(arguments[0]) is str and arguments[0] in trusted[1]:reason=arguments[0]
        trace=trace.tb_next
    return details,reason


def main(arguments=None):
    parser=argparse.ArgumentParser();parser.add_argument('--package',required=True)
    modes=parser.add_mutually_exclusive_group(required=True);modes.add_argument('--prepare',action='store_true');modes.add_argument('--publish',action='store_true')
    parser.add_argument('--acceptance-receipt');args=parser.parse_args(arguments)
    if bool(args.publish)!=bool(args.acceptance_receipt):parser.error('--publish requires its acceptance receipt')
    try:result=execute(args.package,'publish' if args.publish else 'prepare',args.acceptance_receipt)
    except BaseException as error:
        diagnostic,reason=safe_error_details(error,args.package)
        changed=getattr(error,'application_contents_changed',False)
        changed=changed if type(changed) is bool else 'unknown'
        rolled_back=getattr(error,'rollback_complete',True) is True and changed!='unknown'
        result={'format':1,'kind':'go-erp-confirmation-nav-successor','ok':False,'reason':reason,'database_changed':False,
            'credentials_disclosed':False,'published':False,'rollback_complete':rolled_back,
            'application_contents_changed':changed,'diagnostic':diagnostic}
    print(json.dumps(result,sort_keys=True));return 0 if result.get('ok') is True else 78 if result.get('rollback_complete') is False else 1


if __name__=='__main__':raise SystemExit(main())
