#!/usr/bin/env python3
"""Activate only the verified WhatsApp pipeline; no AI, delivery, or messaging calls."""
import argparse
import fcntl
import hashlib
import importlib.util
import json
import os
import pathlib
import pwd
import re
import signal
import stat
import subprocess
import sys
import tempfile
from dataclasses import replace
from datetime import datetime

APP_ROOT = pathlib.Path('/home/fasakha/public_html')
BASE_BLOB = 'f09c87de0206b54b8a8ca7d7359b8e15b32ebe98'
CONFIG_WRITER_BLOB = 'a0780b40adc404f81b5bd2b9934b50d13dc0797a'
CART_DEFAULT_BLOB = 'fc02576f4e7896c7cee33152561ca76c69b669cd'
CACHE_PATH = 'bootstrap/cache/config.php'
CART_PATH = 'config/whatsapp_cart.php'
PROTECTED = ('config/whatsapp_orders.php', 'config/whatsapp_replies.php',
             'app/Services/Dashboard/WhatsAppOrderWorkflow.php',
             'app/Services/Dashboard/WhatsAppInboxConsumer.php')
TARGETS = ('WHATSAPP_ORDERS_MODE', 'WHATSAPP_ORDERS_AUTOMATION_ACTOR_ID',
           'WHATSAPP_ORDERS_ALLOWED_BRANCH_IDS', 'WHATSAPP_ORDERS_ACTIVATION_MESSAGE_ID',
           'WHATSAPP_ORDERS_ACTIVATION_EVENT_ID')
MAX_INT = 9223372036854775807


def verified_module(filename, wanted, name):
    path = pathlib.Path(__file__).absolute().parent / filename
    for parent in reversed(path.parents):
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0, os.geteuid()):
            raise RuntimeError('UNSAFE_HELPER_PATH')
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        info = os.fstat(descriptor)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid not in (0, os.geteuid())
                or info.st_nlink != 1 or stat.S_IMODE(info.st_mode) & 0o022):
            raise RuntimeError('UNSAFE_HELPER_OWNER_OR_TYPE')
        content = os.read(descriptor, 1048577)
    finally:
        os.close(descriptor)
    if hashlib.sha1(b'blob ' + str(len(content)).encode() + b'\0' + content).hexdigest() != wanted:
        raise RuntimeError('HELPER_HASH_NEEDS_REVIEW')
    module = importlib.util.module_from_spec(importlib.util.spec_from_loader(name, loader=None))
    module.__file__ = str(path)
    sys.modules[name] = module
    exec(compile(content, str(path), 'exec'), module.__dict__)
    return module


try:
    base = verified_module('install_whatsapp_inbox.py', BASE_BLOB, 'wa_auto_activation_base')
    writer = verified_module('configure_whatsapp_orders.py', CONFIG_WRITER_BLOB, 'wa_auto_activation_writer')
    # Both verified helpers use identical base bytes. Share one Snapshot/SafeError identity.
    writer.base = base
    writer.SafeError = base.SafeError
except BaseException:
    if __name__ == '__main__':
        print('AUTOMATIC_ACTIVATION_HELPER_VERIFICATION_FAILED', file=sys.stderr)
        sys.exit(1)
    raise
SafeError = base.SafeError


def canonical_id(value, zero=False):
    if not isinstance(value, str) or re.fullmatch(r'(?:0|[1-9][0-9]{0,18})' if zero else r'[1-9][0-9]{0,18}', value) is None or int(value) > MAX_INT:
        raise SafeError('ACTIVATION_INVALID_ID')
    return value


def branches_argument(value):
    if not isinstance(value, str):
        raise SafeError('ACTIVATION_INVALID_BRANCHES')
    ids = value.split(',')
    if not 1 <= len(ids) <= 100 or len(ids) != len(set(ids)):
        raise SafeError('ACTIVATION_INVALID_BRANCHES')
    return [canonical_id(item) for item in ids]


def validate_mappings(mapping, branches):
    if not isinstance(mapping, dict) or len(mapping) > 20:
        raise SafeError('ACTIVATION_INVALID_MAPPING')
    entries = 0
    for catalog, products in mapping.items():
        if not isinstance(catalog, str) or re.fullmatch(r'[1-9][0-9]{0,29}', catalog) is None or not isinstance(products, dict) or len(products) > 300:
            raise SafeError('ACTIVATION_INVALID_MAPPING')
        for retailer, scopes in products.items():
            if (not isinstance(retailer, str) or not retailer or retailer.strip() != retailer
                    or len(retailer.encode('utf-8')) > 200 or re.search(r'[\x00-\x1f\x7f]', retailer)
                    or not isinstance(scopes, dict) or not scopes):
                raise SafeError('ACTIVATION_INVALID_MAPPING')
            for branch, item in scopes.items():
                entries += 1
                if entries > 2000 or branch not in ['f:' + value for value in branches] or not isinstance(item, dict):
                    raise SafeError('ACTIVATION_INVALID_MAPPING_SCOPE')
                if set(item) not in ({'product_id', 'quantity_mode', 'quantity_per_unit'},
                                     {'product_id', 'quantity_mode', 'quantity_per_unit', 'option_id'}):
                    raise SafeError('ACTIVATION_INVALID_MAPPING')
                product = item['product_id']
                if type(product) is int: product = str(product)
                canonical_id(product)
                quantity = item['quantity_per_unit']
                if (item['quantity_mode'] not in ('piece', 'weight') or not isinstance(quantity, str)
                        or re.fullmatch(r'(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,3})?', quantity) is None
                        or not any(char in '123456789' for char in quantity)
                        or (item['quantity_mode'] == 'piece' and '.' in quantity and int(quantity.split('.')[1]) != 0)
                        or not isinstance(item.get('option_id', ''), str)
                        or re.fullmatch(r'(?:|f:[0-9]+:(?:base|extra_clear|extra_clean|extra_vacuim))', item.get('option_id', '')) is None):
                    raise SafeError('ACTIVATION_INVALID_MAPPING')
    return mapping


def validate_names(names, mappings):
    if not isinstance(names, dict) or len(names) > 20:
        raise SafeError('ACTIVATION_INVALID_CATALOG_NAMES')
    for catalog, products in names.items():
        if catalog not in mappings or not isinstance(products, dict) or len(products) > 300:
            raise SafeError('ACTIVATION_INVALID_CATALOG_NAMES')
        for retailer, title in products.items():
            if (retailer not in mappings[catalog] or not isinstance(title, str) or not title
                    or title.strip() != title or len(title) > 200 or len(title.encode('utf-8')) > 800
                    or re.search(r'[\x00-\x1f\x7f]', title)
                    or re.search(r'(?:EAA[A-Za-z0-9_-]{15,}|sk-[A-Za-z0-9_-]{16,})', title)):
                raise SafeError('ACTIVATION_INVALID_CATALOG_NAMES')
    return names


def metadata(path, uid, root, secret=False, absent_ok=False):
    base.safe_ancestors(path, uid, root)
    try: descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except FileNotFoundError:
        if absent_ok: return None
        raise SafeError('ACTIVATION_REQUIRED_FILE_MISSING') from None
    except OSError: raise SafeError('ACTIVATION_FILE_UNSAFE') from None
    try:
        info = os.fstat(descriptor)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != uid
                or (secret and (info.st_nlink != 1 or stat.S_IMODE(info.st_mode) & 0o022))):
            raise SafeError('ACTIVATION_FILE_OWNER_TYPE_LINK_OR_MODE_NEEDS_REVIEW')
        return (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), info.st_dev, info.st_ino,
                info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)
    finally: os.close(descriptor)


def state(root, uid, relative, secret=False, absent_ok=False):
    before = metadata(root / relative, uid, root, secret, absent_ok)
    content = base.snapshot(root / relative, uid, root, absent_ok)
    if metadata(root / relative, uid, root, secret, absent_ok) != before:
        raise SafeError('ACTIVATION_FILE_CHANGED_DURING_READ')
    return content, before


def env_proposal(content, updates):
    text, lines, found = writer.parse_env(content)
    newline = '\r\n' if '\r\n' in text else '\n'
    for name in TARGETS:
        value = updates[name]
        if name in found:
            index, prefix, current, comment, ending = found[name]
            if current != value: lines[index] = prefix + '"' + value + '"' + comment + ending
        else:
            if lines and not lines[-1].endswith(('\n', '\r')): lines[-1] += newline
            lines.append(name + '="' + value + '"' + newline)
    return ''.join(lines).encode('utf-8')


def validate_env_credentials(content, current):
    found = writer.parse_env(content)[2]
    required = {'WHATSAPP_ORDERS_ENABLED':'true','WHATSAPP_ORDERS_MODEL':'gpt-6-luna',
                'WHATSAPP_ORDERS_API_KEY':current['orders']['api_key'],
                'WHATSAPP_REPLIES_ACCESS_TOKEN':current['replies']['access_token']}
    if any(name not in found or found[name][2] != value for name, value in required.items()):
        raise SafeError('ACTIVATION_ENV_EFFECTIVE_CREDENTIALS_DIFFER_CONFIGURATION_UNCHANGED')


PREFLIGHT_PHP = r'''
ini_set('display_errors', '0');
try {
    require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap();
    if ($app->getCachedConfigPath() !== getcwd().'/bootstrap/cache/config.php') exit(1);
    $input = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
    $orders = config('whatsapp_orders'); $replies = config('whatsapp_replies');
    if (!is_array($orders) || ($orders['enabled']??null)!==true || ($orders['mode']??null)!=='review'
        || ($orders['model']??null)!=='gpt-6-luna' || !is_string($orders['api_key']??null) || $orders['api_key']===''
        || !is_array($replies) || !is_string($replies['access_token']??null) || $replies['access_token']===''
        || !app(\App\Services\Dashboard\WhatsAppOrderWorkflow::class)->available()
        || !(new \ReflectionClass(\App\Services\Dashboard\WhatsAppOrderWorkflow::class))->hasConstant('AUTOMATIC_ACTIVATION_TIME_GUARD')
        || (new \ReflectionClass(\App\Services\Dashboard\WhatsAppOrderWorkflow::class))->getConstant('AUTOMATIC_ACTIVATION_TIME_GUARD')!=='whatsapp-auto-activation-time-v1'
        || !(new \ReflectionClass(\App\Services\Dashboard\WhatsAppOrderWorkflow::class))->hasMethod('activationInstant')
        || !array_key_exists('whatsapp:process-orders', $kernel->all())
        || !defined(\App\Services\Dashboard\WhatsAppInboxConsumer::class.'::QUARANTINE_BATCH_RECHECK')
        || \App\Services\Dashboard\WhatsAppInboxConsumer::QUARANTINE_BATCH_RECHECK!==true) exit(1);
    $actor = \App\Models\User::withoutGlobalScopes()->find($input['actor']); if (!$actor) exit(1);
    $actor = app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->actor($actor);
    $access = app(\App\Services\Dashboard\TakeawayAccess::class);
    if (!($access->permissions($actor)['can_checkout']??false)) exit(1);
    foreach ($input['branches'] as $id) {
        $b = $access->branch('f:'.$id, $actor); if (($b['value']??null)!=='f:'.$id || ($b['kind']??null)!=='f') exit(1);
    }
    foreach (['whatsapp_webhook_events','whatsapp_inbox_messages','whatsapp_inbox_conversations',
        'whatsapp_inbox_ingestion_failures','whatsapp_order_drafts','whatsapp_order_scans'] as $table) {
        if (!\Illuminate\Support\Facades\Schema::hasTable($table)) exit(1);
    }
    $cart = config('whatsapp_cart'); if ($cart===null) $cart=require 'config/whatsapp_cart.php';
    if (!is_array($cart)||!is_array($cart['product_mappings']??null)||!is_array($cart['branch_aliases']??null)) exit(1);
    // Mapping IDs bind to freshly authorized live branch rows, available products, and exact allowed options.
    $mappings=$input['mapping']===null?$cart['product_mappings']:$input['mapping'];
    $catalog=app(\App\Services\Dashboard\TakeawayCatalog::class); $lists=[];
    foreach ($mappings as $products) foreach ($products as $scopes) foreach ($scopes as $branch=>$item) {
        if (!in_array(substr($branch,2),$input['branches'],true)) exit(1);
        if (!isset($lists[$branch])) {
            $list=[];
            for($page=1;$page<=3;$page++) {
                $p=$catalog->listing(['branch'=>$branch,'page'=>$page,'per_page'=>100],$actor);
                $list=array_merge($list,$p['items']);
                if ((int)($p['pagination']['last_page']??1)<=$page) break;
                if ($page===3) exit(1);
            }
            $lists[$branch]=$list;
        }
        $matches=array_values(array_filter($lists[$branch],static fn($p)=>(string)$p['id']===(string)$item['product_id']&&($p['available']??false)));
        if(count($matches)!==1||!in_array($matches[0]['quantity_mode']??'select',[$item['quantity_mode'],'select'],true)) exit(1);
        $option=$item['option_id']??'';
        if($option!==''&&count(array_filter($matches[0]['options']??[],static fn($o)=>$o['id']===$option))!==1) exit(1);
    }
    $state=['orders'=>$orders,'replies'=>$replies,'cart'=>$cart,
        'fingerprint'=>hash('sha256',serialize([$orders,$replies,$cart]))];
    $bytes=json_encode($state,JSON_THROW_ON_ERROR);
    if(file_put_contents($argv[2],$bytes)!==strlen($bytes))exit(1); chmod($argv[2],0600);
} catch(\Throwable $e) { exit(1); }
'''

CUTOVER_PHP = r'''
ini_set('display_errors','0');
try {
    require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if(config('whatsapp_orders.mode')!=='review'||\Illuminate\Support\Facades\DB::connection()->getDriverName()!=='mysql') exit(1);
    $consumer=app(\App\Services\Dashboard\WhatsAppInboxConsumer::class); $seen=0;$processed=0;$quarantined=0;
    for($batch=0;$batch<10;$batch++) {
        $s=$consumer->consume(200); if(($s['errors']??1)!==0||!is_int($s['events_seen']??null)||$s['events_seen']<0||$s['events_seen']>200)exit(1);
        $seen+=$s['events_seen'];$processed+=$s['events_processed'];$quarantined+=$s['quarantined_events'];
        if($s['events_seen']===0)break;
    }
    $baseline=\Illuminate\Support\Facades\DB::transaction(function() {
        // Locking reads wait for UI projections selected before this transaction. Their messages commit first.
        $last=\Illuminate\Support\Facades\DB::table('whatsapp_webhook_events')->orderByDesc('id')->lockForUpdate()->first(['id']);
        $event=$last?(int)$last->id:0;
        $pending=\Illuminate\Support\Facades\DB::table('whatsapp_webhook_events')->where('id','<=',$event)
            ->whereNull('processed_at')->orderBy('id')->limit(2001)->lockForUpdate()->get(['id']);
        if(count($pending)>2000)throw new \RuntimeException();
        foreach($pending as $row) {
            $failure=\Illuminate\Support\Facades\DB::table('whatsapp_inbox_ingestion_failures')
                ->where('event_id',$row->id)->lockForUpdate()->first(['event_id']);
            if(!$failure)throw new \RuntimeException();
        }
        $runnable=\Illuminate\Support\Facades\DB::table('whatsapp_webhook_events')->where('id','<=',$event)->whereNull('processed_at')
            ->whereNotExists(function($q){$q->select('event_id')->from('whatsapp_inbox_ingestion_failures')
                ->whereColumn('whatsapp_inbox_ingestion_failures.event_id','whatsapp_webhook_events.id');})->count();
        if($runnable!==0)throw new \RuntimeException();
        $message=(int)\Illuminate\Support\Facades\DB::table('whatsapp_inbox_messages as m')
            ->join('whatsapp_inbox_conversations as c','c.id','=','m.conversation_id')
            ->where('c.waba_id','468336579702269')->where('c.phone_number_id','515388018324075')->max('m.id');
        $clock=\Illuminate\Support\Facades\DB::selectOne('SELECT UTC_TIMESTAMP() AS activation_time');
        if(!is_string($clock->activation_time??null)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/',$clock->activation_time))throw new \RuntimeException();
        return ['event_ceiling'=>$event,'message_ceiling'=>$message,'pending_runnable'=>0,
            'quarantines_retained'=>count($pending),'activated_at'=>$clock->activation_time];
    },3);
    $baseline['events_seen']=$seen;$baseline['events_processed']=$processed;$baseline['quarantined_events']=$quarantined;
    $bytes=json_encode($baseline,JSON_THROW_ON_ERROR);if(file_put_contents($argv[1],$bytes)!==strlen($bytes))exit(1);chmod($argv[1],0600);
}catch(\Throwable $e){exit(1);}
'''

STAGE_PHP = r'''
ini_set('display_errors','0');
try {
    $updates=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
    $cart=$updates['cart']; $bytes='<?php return '.var_export($cart,true).';'.PHP_EOL;
    if(file_put_contents($argv[2],$bytes)!==strlen($bytes))exit(1);chmod($argv[2],0600);
    if((require $argv[2])!==$cart)exit(1);
    if($argv[3]!=='') {
        $original=require $argv[3];if(!is_array($original)||!is_array($original['whatsapp_orders']??null))exit(1);
        foreach($updates['orders'] as $key=>$value)$original['whatsapp_orders'][$key]=$value;
        $original['whatsapp_cart']=$cart;
        $bytes='<?php return '.var_export($original,true).';'.PHP_EOL;
        if(file_put_contents($argv[4],$bytes)!==strlen($bytes))exit(1);chmod($argv[4],0600);
        if((require $argv[4])!==$original)exit(1);
    }
}catch(\Throwable $e){exit(1);}
'''

POSTFLIGHT_PHP = r'''
ini_set('display_errors','0');
try {
    require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
    $expected=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
    $before=json_decode(file_get_contents($argv[2]),true,64,JSON_THROW_ON_ERROR);
    $actual=config('whatsapp_orders');if(!is_array($actual))exit(1);
    foreach($expected['orders'] as $key=>$value){$v=$actual[$key]??null;if(in_array($key,['automation_actor_id','activation_message_id','activation_event_id'],true))$v=(string)$v;if($v!==(in_array($key,['automation_actor_id','activation_message_id','activation_event_id'],true)?(string)$value:$value))exit(1);}
    if(($actual['enabled']??null)!==true||($actual['model']??null)!=='gpt-6-luna'||($actual['api_key']??null)!==$before['orders']['api_key']
        ||config('whatsapp_replies')!==$before['replies']||config('whatsapp_cart')!==$expected['cart']
        ||!app(\App\Services\Dashboard\WhatsAppOrderWorkflow::class)->available()
        ||!array_key_exists('whatsapp:process-orders',$kernel->all()))exit(1);
    // This pure guard validates the actual runtime clock/calendar contract without analyzing a conversation.
    $workflow=app(\App\Services\Dashboard\WhatsAppOrderWorkflow::class);
    $guard=new \ReflectionMethod($workflow,'activationInstant');$guard->setAccessible(true);
    if($guard->invoke($workflow)!==$expected['cart']['activated_at'])exit(1);
    $actor=\App\Models\User::withoutGlobalScopes()->find($expected['orders']['automation_actor_id']);if(!$actor)exit(1);
    $actor=app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->actor($actor);$access=app(\App\Services\Dashboard\TakeawayAccess::class);
    if(!($access->permissions($actor)['can_checkout']??false))exit(1);
    foreach($expected['orders']['allowed_branch_ids'] as $id){$b=$access->branch('f:'.$id,$actor);if(($b['value']??null)!=='f:'.$id)exit(1);}
    echo '{"automatic_ready":true,"network_calls":0,"orders_created":0}';
}catch(\Throwable $e){exit(1);}
'''


def command(root, arguments, capture=False, timeout=60):
    try:
        result = subprocess.run(arguments, cwd=root, stdout=subprocess.PIPE if capture else subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, timeout=timeout, check=False)
        return result.returncode, result.stdout if capture and len(result.stdout) <= 65536 else b''
    except (OSError, subprocess.TimeoutExpired): return 1, b''


def read_json_private(path, uid, root):
    try: return json.loads(writer.secure_snapshot(path, uid, root).content)
    except (ValueError, TypeError): raise SafeError('ACTIVATION_PRIVATE_RESULT_INVALID') from None


def activate(root, uid, actor, branches, mapping_path=None, runner=command):
    actor = canonical_id(actor)
    branches = branches_argument(','.join(branches))
    base.validate_root(root, uid)
    parent = root.parent / 'whatsapp-release-backups'; base.private_parent(parent, uid)
    lock = os.open(parent / 'orders-install.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(lock)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_nlink != 1 or stat.S_IMODE(info.st_mode) != 0o600:
            raise SafeError('ACTIVATION_LOCK_UNSAFE')
        try: fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError: raise SafeError('ANOTHER_ORDERS_INSTALL_OR_CONFIGURATION_IS_RUNNING') from None
        before = {p: state(root, uid, p, p in ('.env', CACHE_PATH), p == CACHE_PATH)
                  for p in ('.env', CACHE_PATH, CART_PATH, *PROTECTED)}
        if base.blob_hash(before[CART_PATH][0].content) != CART_DEFAULT_BLOB:
            raise SafeError('ACTIVATION_CART_SOURCE_NEEDS_REVIEW')
        writer.parse_env(before['.env'][0].content)
        mapping = None; names = None
        if mapping_path is not None:
            path = pathlib.Path(mapping_path)
            data = writer.secure_snapshot(path, uid, path.parent).content
            if len(data) > 524288: raise SafeError('ACTIVATION_MAPPING_TOO_LARGE')
            try: document = json.loads(data, object_pairs_hook=unique_json_object)
            except (ValueError, TypeError): raise SafeError('ACTIVATION_MAPPING_JSON_INVALID') from None
            if isinstance(document, dict) and set(document) == {'product_mappings','product_names'}:
                mapping = document['product_mappings']; names = document['product_names']
            else: mapping = document
            validate_mappings(mapping, branches)
            if names is not None: validate_names(names, mapping)
        backup = pathlib.Path(tempfile.mkdtemp(prefix='automatic-activation-', dir=parent)); backup.chmod(0o700)
        if backup.stat().st_dev != root.stat().st_dev: raise SafeError('ACTIVATION_BACKUP_FILESYSTEM_NEEDS_REVIEW')
        publications = backup / 'publications'; publications.mkdir(mode=0o700); base.probe_atomic_exchange(publications)
        for p in ('.env', CACHE_PATH, CART_PATH):
            if before[p][0] is not None: writer.write_private(backup / {'.env':'env.before', CACHE_PATH:'config.before.php', CART_PATH:'cart.before.php'}[p], before[p][0].content)
        inputs = backup / 'inputs.json'; writer.write_private(inputs, json.dumps({'actor':actor,'branches':branches,'mapping':mapping}, ensure_ascii=True).encode())
        current_path = backup / 'current.json'
        code, _ = runner(root, ['php', '-r', PREFLIGHT_PHP, str(inputs), str(current_path)])
        if code != 0: raise SafeError('ACTIVATION_REVIEW_ACTOR_BRANCH_OR_RUNTIME_NOT_READY')
        current = read_json_private(current_path, uid, root)
        validate_current(current)
        validate_env_credentials(before['.env'][0].content, current)
        if mapping is None: mapping = validate_mappings(current['cart']['product_mappings'], branches)
        if names is None: names = current['cart']['product_names']
        validate_names(names, mapping)

        def unchanged(written=None):
            written = written or {}
            for p, (original, info) in before.items():
                expected = written[p].after if p in written else original
                actual, actual_info = state(root, uid, p, p in ('.env', CACHE_PATH), p == CACHE_PATH)
                if actual != expected or (p not in written and actual_info != info):
                    raise SafeError('ACTIVATION_CONFIGURATION_CHANGED_CONCURRENTLY')

        unchanged()
        cutover_path = backup / 'cutover.json'
        code, _ = runner(root, ['php', '-r', CUTOVER_PHP, str(cutover_path)], timeout=60)
        if code != 0: raise SafeError('ACTIVATION_HISTORY_NOT_DRAINED_CONFIGURATION_UNCHANGED')
        cutover = read_json_private(cutover_path, uid, root); validate_cutover(cutover)
        unchanged()
        orders = {'mode':'auto','automation_actor_id':actor,'allowed_branch_ids':branches,
                  'activation_message_id':cutover['message_ceiling'],'activation_event_id':cutover['event_ceiling']}
        cart = dict(current['cart']); cart['product_mappings'] = mapping; cart['product_names'] = names; cart['debounce_seconds'] = 10
        cart['activated_at'] = cutover['activated_at']
        updates = {'orders':orders, 'cart':cart}
        settings = backup / 'activation-settings.json'; writer.write_private(settings, json.dumps(updates, ensure_ascii=True).encode())
        cart_candidate = backup / 'cart.candidate.php'; cache_candidate = backup / 'config.candidate.php'
        code, _ = runner(root, ['php','-r',STAGE_PHP,str(settings),str(cart_candidate),
            str(backup / 'config.before.php') if before[CACHE_PATH][0] else '',str(cache_candidate)])
        if code != 0: raise SafeError('ACTIVATION_CACHE_OR_CART_STAGING_FAILED')
        values = {'WHATSAPP_ORDERS_MODE':'auto','WHATSAPP_ORDERS_AUTOMATION_ACTOR_ID':actor,
                  'WHATSAPP_ORDERS_ALLOWED_BRANCH_IDS':','.join(branches),
                  'WHATSAPP_ORDERS_ACTIVATION_MESSAGE_ID':str(cutover['message_ceiling']),
                  'WHATSAPP_ORDERS_ACTIVATION_EVENT_ID':str(cutover['event_ceiling'])}
        proposals = {CART_PATH:writer.secure_snapshot(cart_candidate, uid, root).content,
                     '.env':env_proposal(before['.env'][0].content, values)}
        if before[CACHE_PATH][0]: proposals[CACHE_PATH] = writer.secure_snapshot(cache_candidate, uid, root).content
        for p in (cart_candidate, cache_candidate if before[CACHE_PATH][0] else None):
            if p is not None:
                code, _ = runner(root, ['php','-l',str(p)])
                if code != 0: raise SafeError('ACTIVATION_CANDIDATE_LINT_FAILED')
        written, created = {}, []
        try:
            unchanged()
            for p, content in proposals.items():
                unchanged(written)
                original = before[p][0]
                restore = replace(original, mode=0o600) if p in ('.env', CACHE_PATH) else original
                base.replace_file(root / p, content, original, uid, root, created, restore=restore,
                                  journal=written, relative=p, private_directory=publications)
            code, output = runner(root, ['php','-r',POSTFLIGHT_PHP,str(settings),str(current_path)], capture=True)
            try: readiness = json.loads(output) if code == 0 else None
            except (ValueError, TypeError): readiness = None
            if readiness != {'automatic_ready':True,'network_calls':0,'orders_created':0}:
                raise SafeError('ACTIVATION_EFFECTIVE_CHECK_FAILED')
            unchanged(written)
            receipt = {'actor_id':actor,'branch_ids':branches,**cutover,'automatic_ready':True,
                       'mapping_catalogs':len(mapping),'network_calls':0,'orders_created':0}
            writer.write_private(backup / 'receipt.json', json.dumps(receipt, sort_keys=True).encode())
            return 'WHATSAPP_AUTOMATIC_CONFIGURED_TIMER_START_REQUIRED', backup, receipt
        except BaseException:
            restored = True
            with base.blocked_signals():
                for publication in reversed(list(written.values())):
                    try: restored = base.recover_publication(publication, root, uid) and restored
                    except BaseException: restored = False
            raise SafeError(('ACTIVATION_CONFIGURATION_RESTORED' if restored else
                             'ACTIVATION_ROLLBACK_STOPPED_CONCURRENT_FILES_RETAINED')+'; BACKUP='+str(backup)) from None
    finally: os.close(lock)


def unique_json_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result: raise ValueError('DUPLICATE_JSON_KEY')
        result[key] = value
    return result


def validate_current(current):
    if not isinstance(current, dict) or set(current) != {'orders','replies','cart','fingerprint'}:
        raise SafeError('ACTIVATION_CURRENT_CONFIGURATION_INVALID')
    orders = current['orders']; replies = current['replies']; cart = current['cart']
    if isinstance(cart,dict):
        for key in ('product_mappings','product_names'):
            if cart.get(key,[])==[]: cart[key]={}
    if (not isinstance(orders, dict) or orders.get('enabled') is not True or orders.get('mode') != 'review'
            or orders.get('model') != 'gpt-6-luna' or not isinstance(orders.get('api_key'), str) or not orders['api_key']
            or not isinstance(replies, dict) or not isinstance(replies.get('access_token'), str) or not replies['access_token']
            or not isinstance(cart, dict) or not isinstance(cart.get('product_mappings'), dict)
            or not isinstance(cart.get('product_names'), dict)
            or not isinstance(cart.get('branch_aliases'), dict) or cart.get('debounce_seconds') != 10
            or not isinstance(current['fingerprint'], str) or re.fullmatch('[0-9a-f]{64}', current['fingerprint']) is None):
        raise SafeError('ACTIVATION_CURRENT_CONFIGURATION_INVALID')
    for branch, aliases in cart['branch_aliases'].items():
        canonical_id(str(branch))
        if (not isinstance(aliases, list) or not 1 <= len(aliases) <= 20
                or any(not isinstance(value,str) or not value.strip() or len(value.encode('utf-8'))>200
                       or re.search(r'[\x00-\x1f\x7f]',value) for value in aliases)):
            raise SafeError('ACTIVATION_INVALID_BRANCH_ALIASES')


def validate_cutover(value):
    expected={'event_ceiling','message_ceiling','pending_runnable','quarantines_retained','activated_at',
              'events_seen','events_processed','quarantined_events'}
    if not isinstance(value,dict) or set(value)!=expected or value['pending_runnable']!=0:
        raise SafeError('ACTIVATION_HISTORY_NOT_DRAINED_CONFIGURATION_UNCHANGED')
    for key in expected-{'activated_at'}:
        if type(value[key]) is not int or not 0<=value[key]<=MAX_INT:
            raise SafeError('ACTIVATION_CUTOVER_INVALID')
    if value['events_seen']>2000 or value['events_processed']>value['events_seen'] or value['quarantines_retained']>2000:
        raise SafeError('ACTIVATION_CUTOVER_INVALID')
    if not isinstance(value['activated_at'],str) or re.fullmatch(r'20[0-9]{2}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}',value['activated_at']) is None:
        raise SafeError('ACTIVATION_CUTOVER_INVALID')
    try:
        datetime.strptime(value['activated_at'], '%Y-%m-%d %H:%M:%S')
    except ValueError:
        raise SafeError('ACTIVATION_CUTOVER_INVALID') from None


def main():
    try:
        parser=argparse.ArgumentParser(description=__doc__)
        parser.add_argument('--root',required=True);parser.add_argument('--actor',required=True)
        parser.add_argument('--branches',required=True);parser.add_argument('--mapping')
        args=parser.parse_args()
        if args.root!=str(APP_ROOT) or os.geteuid()!=pwd.getpwnam('fasakha').pw_uid:
            raise SafeError('RUN_AS_FASAKHA_FOR_CONFIRMED_PROJECT_ROOT')
        signal.signal(signal.SIGTERM,lambda *_:(_ for _ in ()).throw(SafeError('ACTIVATION_INTERRUPTED')))
        status,backup,receipt=activate(APP_ROOT,os.geteuid(),args.actor,branches_argument(args.branches),args.mapping)
        print(status);print('BACKUP='+str(backup));print('ACTIVATION='+json.dumps(receipt,sort_keys=True))
        return 0
    except SafeError as error: print(str(error),file=sys.stderr)
    except BaseException: print('ACTIVATION_STOPPED_WITHOUT_SECRET_OUTPUT',file=sys.stderr)
    return 1


if __name__=='__main__': sys.exit(main())
