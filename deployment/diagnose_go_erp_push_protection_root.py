#!/usr/bin/env python3
"""Preserve the original worker retry and disclose only proven rejection metadata."""
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import sys

RETRY_PIN = '8da3f1f89a2ab6ecbf5979942c97c7705510f2c1'
REV = re.compile(r'[a-f0-9]{40}\Z')
ORIGINS = frozenset(('CANDIDATE_COMMIT','SEED_COMMIT','SEED_ANCESTOR','UNVERIFIED_COMMIT'))
CHANGES = frozenset(('ADDED_TO_CANDIDATE','MODIFIED_IN_CANDIDATE','INHERITED_SEED_BLOB','SEED_HISTORY'))
TYPES = {
    'github personal access token':'GITHUB_PERSONAL_ACCESS_TOKEN',
    'github fine-grained personal access token':'GITHUB_FINE_GRAINED_PERSONAL_ACCESS_TOKEN',
    'github oauth access token':'GITHUB_OAUTH_TOKEN',
    'github oauth token':'GITHUB_OAUTH_TOKEN',
    'google api key':'GOOGLE_API_KEY',
    'google oauth client secret':'GOOGLE_OAUTH_CLIENT_SECRET',
    'google cloud service account credentials':'GOOGLE_SERVICE_ACCOUNT_KEY',
    'google service account key':'GOOGLE_SERVICE_ACCOUNT_KEY',
    'stripe secret key':'STRIPE_SECRET_KEY',
    'stripe api key':'STRIPE_SECRET_KEY',
    'stripe test secret key':'STRIPE_SECRET_KEY',
    'amazon aws access key id':'AWS_ACCESS_KEY_ID',
    'aws access key id':'AWS_ACCESS_KEY_ID',
    'amazon aws secret access key':'AWS_SECRET_ACCESS_KEY',
    'aws secret access key':'AWS_SECRET_ACCESS_KEY',
    'openai api key':'OPENAI_API_KEY',
    'private key':'PRIVATE_KEY',
}

def safe_path(name):
    return (isinstance(name,str) and re.fullmatch(r'[A-Za-z0-9_.@/-]{1,512}',name) is not None
            and all(part not in ('','.','..') for part in name.split('/')))

def parse_rejection(stderr,known_paths,desired_commit,base_revision,commit_kind=None,path_kind=None):
    report = {'locations':[],'location_count':0,'redacted_count':0,'complete':True,'origin_counts':{}}
    if not isinstance(stderr,bytes) or not REV.fullmatch(desired_commit) or not REV.fullmatch(base_revision):
        report['complete'] = False
        return report
    # The pinned retry hands the classifier at most 64 KiB of stderr.
    if len(stderr) >= 65536:
        report['complete'] = False
    message = stderr[:131072].decode('utf-8','replace')
    known = set(known_paths)
    kind,commit = 'UNKNOWN_SECRET_TYPE',None
    for raw in message.splitlines():
        if not raw.startswith('remote:'):
            continue
        line = raw[len('remote:'):].strip()
        heading = re.fullmatch(r'[-—─━]{2,}\s*(.*?)\s*[-—─━]{2,}',line)
        if heading:
            kind = TYPES.get(heading[1].strip().lower(),'UNKNOWN_SECRET_TYPE')
            commit = None
            continue
        match = re.fullmatch(r'(?:-\s*)?commit:\s*([a-f0-9]{40})',line)
        if match:
            commit = match[1]
            continue
        if re.match(r'(?:-\s*)?commit:',line):
            commit = None
            report['complete'] = False
            continue
        if not line.startswith('path:'):
            continue
        report['location_count'] += 1
        if report['location_count'] > 64:
            report['complete'] = False
            break
        location = re.fullmatch(r'path:\s*(.{1,512}):([1-9][0-9]{0,8})',line)
        origin = ('CANDIDATE_COMMIT' if commit == desired_commit else
                  'SEED_COMMIT' if commit == base_revision else 'UNVERIFIED_COMMIT')
        if commit is not None and origin == 'UNVERIFIED_COMMIT' and commit_kind is not None:
            try:
                value = commit_kind(commit)
                if value == 'SEED_ANCESTOR':
                    origin = value
            except Exception:
                report['complete'] = False
        report['origin_counts'][origin] = report['origin_counts'].get(origin,0)+1
        if (location is None or commit is None or origin == 'UNVERIFIED_COMMIT'
                or location[1] not in known or not safe_path(location[1])):
            report['redacted_count'] += 1
            continue
        item = {'secret_type':kind,'commit':commit,'path':location[1],
                'line':int(location[2]),'origin':origin}
        if path_kind is not None:
            try:
                change = path_kind(commit,location[1])
            except Exception:
                change = None
                report['complete'] = False
            if change not in CHANGES:
                report['redacted_count'] += 1
                continue
            item['change'] = change
        report['locations'].append(item)
    if report['location_count'] == 0:
        report['complete'] = False
    return report

def protection_details(doc,source,worker,stderr):
    """Called during failed push classification, under the original watcher lock."""
    directory = doc['Directory'](worker.WORK/'state',0o700)
    try:
        raw = directory.read('current.json',0o600,worker.MAX_JSON)
        state = worker.parse_json(raw)
        doc['safe_state'](state)
        desired = state.get('desired')
        if not isinstance(desired,dict) or not REV.fullmatch(desired.get('commit','')):
            raise ValueError()
        candidate = Path(desired.get('candidatePath',''))
        if (candidate.parent != worker.WORK/'staging'
                or not re.fullmatch(r'candidate-[1-9][0-9]*-[a-f0-9]{16}',candidate.name)
                or not candidate.name.endswith('-'+desired['inventorySha256'][:16])):
            raise ValueError()
        held = doc['Directory'](candidate,0o700)
        try:
            if source._trusted_git(candidate) != desired['commit']:
                raise ValueError()
            base = source._git(candidate,['rev-parse','HEAD^']).decode('ascii').strip()
            if base != doc['BASE']:
                raise ValueError()
            root_fd,_ = source._open_root(candidate,trusted=True)
            try:
                data,_,_ = source._read_at(root_fd,source.CANDIDATE_PATH,trusted=True)
            finally:
                os.close(root_fd)
            metadata = worker.parse_json(data)
            if metadata != source._candidate_metadata(state['observed'],desired['version'],state['counter']):
                raise ValueError()
            def tree(revision):
                rows = source._git(candidate,['ls-tree','-r','-z','--full-tree',revision]).split(b'\0')
                if len(rows) > 50001:
                    raise ValueError()
                result = {}
                for row in rows:
                    if not row:
                        continue
                    header,name = row.split(b'\t',1)
                    mode,kind,pin = header.decode('ascii').split(' ')
                    name = name.decode('utf-8')
                    if kind == 'blob' and REV.fullmatch(pin) and source._path_ok(name):
                        result[name] = pin
                return result
            seed_tree,current_tree = tree(base),tree(desired['commit'])
            known = set(seed_tree)|set(state['observed']['inventory'])
            history = source._git(candidate,['rev-list','--max-count=10000',base]).decode('ascii').splitlines()
            ancestors = {value for value in history if REV.fullmatch(value)}
            trees = {base:seed_tree,desired['commit']:current_tree}
            def commit_kind(commit):
                return 'SEED_ANCESTOR' if commit in ancestors and commit != base else 'UNVERIFIED_COMMIT'
            def path_kind(commit,name):
                if commit not in trees:
                    if len(trees) >= 10:
                        return None
                    trees[commit] = tree(commit)
                if name not in trees[commit]:
                    return None
                if commit != desired['commit']:
                    return 'SEED_HISTORY'
                if name not in seed_tree:
                    return 'ADDED_TO_CANDIDATE'
                return ('INHERITED_SEED_BLOB' if current_tree[name] == seed_tree[name]
                        else 'MODIFIED_IN_CANDIDATE')
            result = parse_rejection(stderr,known,desired['commit'],base,commit_kind,path_kind)
            held.verify(); directory.verify()
            if directory.read('current.json',0o600,worker.MAX_JSON) != raw:
                raise ValueError()
            return result
        finally:
            held.close()
    finally:
        directory.close()

def run_wrapper(retry,doc,source,worker,publisher):
    original = retry['classify']
    captured = None
    def classify(returncode,stdout,stderr):
        nonlocal captured
        category = original(returncode,stdout,stderr)
        if category == 'PUSH_PROTECTION':
            try:
                captured = protection_details(doc,source,worker,stderr)
            except Exception:
                captured = {'complete':False,'reason':'UNKNOWN_PRIVATE'}
        return category
    retry['classify'] = classify
    try:
        result = retry['retry_once'](doc,source,worker,publisher)
    except Exception:
        result = {'ok':False,'reason':'UNKNOWN_PRIVATE','credentials_disclosed':False}
    finally:
        retry['classify'] = original
    if captured is not None:
        result['push_protection'] = captured
    return result

def private_retry(name):
    path = Path(name)
    if not path.is_absolute() or path.resolve() != path:
        raise ValueError()
    for parent in (path.parent,*path.parent.parents):
        value = parent.lstat()
        sticky = parent != path.parent and bool(value.st_mode & stat.S_ISVTX)
        if not stat.S_ISDIR(value.st_mode) or value.st_uid != 0 or value.st_mode & 0o022 and not sticky:
            raise ValueError()
    parent = path.parent.lstat()
    if stat.S_IMODE(parent.st_mode) != 0o700:
        raise ValueError()
    fd = os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        if (not stat.S_ISREG(before.st_mode) or (before.st_uid,before.st_gid,before.st_nlink,
                stat.S_IMODE(before.st_mode)) != (0,0,1,0o600) or not 0 < before.st_size <= 512*1024):
            raise ValueError()
        data = os.read(fd,512*1024+1)
        key = lambda value:(value.st_dev,value.st_ino,value.st_mode,value.st_uid,value.st_gid,
                            value.st_nlink,value.st_size,value.st_mtime_ns,value.st_ctime_ns)
        if (len(data) != before.st_size or key(before) != key(os.fstat(fd)) or key(before) != key(path.lstat())
                or hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest() != RETRY_PIN):
            raise ValueError()
        result = {'__name__':'reviewed_push_protection_retry'}
        exec(compile(data,str(path),'exec'),result)
        return result
    finally:
        os.close(fd)

def main(args=None):
    try:
        args = sys.argv[1:] if args is None else args
        if len(args) != 2 or os.geteuid() != 0 or socket.gethostname().split('.')[0] != 'srv1836524':
            raise ValueError()
        retry = private_retry(args[0])
        doc = retry['private_doctor'](args[1])
        source,worker,publisher = doc['load_installed'](doc['INSTALL'],retry['MODULE_PINS'])
        result = run_wrapper(retry,doc,source,worker,publisher)
    except Exception:
        result = {'ok':False,'reason':'UNKNOWN_PRIVATE','credentials_disclosed':False}
    print(json.dumps(result,sort_keys=True))
    return 0 if result['ok'] else 1

if __name__ == '__main__':
    sys.exit(main())
