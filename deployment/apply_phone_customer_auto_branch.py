#!/usr/bin/env python3
"""Apply delivery customer branch selection without replaying other deployments."""
import argparse
import datetime
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import tempfile

BASE_REVISION = 'c27278519f923a2fe351523ff3a149f14a38c04d'
UTILITY_REVISION = '6582750c2ef925500504a83d89141e49f0bd62a3'
UTILITY_PATH = 'deployment/apply_pos_inline_quantity.py'
UTILITY_BLOB = 'a438bfdcb17f43ef11605bcb48de926c190b3f73'
SOURCE_MANIFEST = {
    'app/Services/Dashboard/PosServicePhone.php': 'M',
    'public/dashboard/js/phone-orders.js': 'M',
}


def load_common(root):
    """Use pinned filesystem/Git helpers without running prior deployments."""
    source = subprocess.run(['git', '-C', str(root), 'show', UTILITY_REVISION + ':' + UTILITY_PATH],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)
    if source.returncode != 0:
        raise RuntimeError('PINNED_UTILITY_UNAVAILABLE')
    checked = subprocess.run(['git', 'hash-object', '--no-filters', '--stdin'], input=source.stdout,
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)
    if checked.returncode != 0 or checked.stdout.decode().strip() != UTILITY_BLOB:
        raise RuntimeError('PINNED_UTILITY_MISMATCH')
    with tempfile.TemporaryDirectory(prefix='phone-customer-branch-utility-') as temporary:
        helper = Path(temporary) / 'utility.py'
        helper.write_bytes(source.stdout)
        spec = importlib.util.spec_from_file_location('pinned_phone_customer_branch_utility', helper)
        common = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(common)
    return common


def build_plan(c, root, revision):
    plan = {}
    with tempfile.TemporaryDirectory(prefix='phone-customer-branch-plan-') as temporary:
        for relative, status in SOURCE_MANIFEST.items():
            before = c.source(root, BASE_REVISION, relative, optional=status == 'A')
            c.require((before is None) == (status == 'A'), 'SOURCE_STATUS_INVALID: ' + relative)
            after = c.source(root, revision, relative)
            c.require(before != after, 'RELEASE_CHANGE_MISSING: ' + relative)
            plan[relative] = {'status': status, 'patch': c.make_patch(Path(temporary), relative, before, after)}
    return plan


def lint_php(c, php, directory):
    for relative in SOURCE_MANIFEST:
        if relative.endswith('.php') and not relative.endswith('.blade.php'):
            c.require(c.run([php, '-l', str(directory / relative)]).returncode == 0,
                      'PHP_SYNTAX_INVALID: ' + relative)


PROBE = r'''<?php
ini_set('display_errors','0');ini_set('log_errors','0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$level=ob_get_level();ob_start();$exit=1;$result=['ok'=>false,'reason'=>'PHONE_CUSTOMER_BRANCH_RUNTIME_FAILED'];
$known=['PHONE_CUSTOMER_BRANCH_RUNTIME_FAILED','PHONE_CUSTOMER_ROUTE_INVALID','PHONE_CUSTOMER_MIDDLEWARE_INVALID',
 'PHONE_CUSTOMER_METHOD_REQUIRED','PHONE_CUSTOMER_SCHEMA_REQUIRED'];
try {
 $root=$argv[1];require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 $app->make(\Illuminate\Contracts\Http\Kernel::class);
 $router=$app['router'];$route=$router->getRoutes()->getByName('phone-orders.customers');
 if(!$route || $route->uri()!=='admin/phone-orders/customers' || !in_array('GET',$route->methods(),true)
   || ltrim($route->getActionName(),'\\')!=='App\\Http\\Controllers\\Dashboard\\PhoneOrdersController@customers')
  throw new \RuntimeException('PHONE_CUSTOMER_ROUTE_INVALID');
 $middleware=$route->gatherMiddleware();$resolved=$router->gatherRouteMiddleware($route);
 if(!in_array('web',$middleware,true) || !in_array('IsAdmin',$middleware,true)
   || !in_array(\App\Http\Middleware\IsAdmin::class,$resolved,true)
   || !in_array(\App\Http\Middleware\VerifyCsrfToken::class,$resolved,true)
   || !in_array(\Illuminate\Session\Middleware\StartSession::class,$resolved,true))
  throw new \RuntimeException('PHONE_CUSTOMER_MIDDLEWARE_INVALID');
 foreach(['App\\Services\\Dashboard\\PosServicePhone'=>['customers'],
          'App\\Services\\Dashboard\\TakeawayAccess'=>['actor','branch','branches'],
          'App\\Services\\Dashboard\\BranchCustomers'=>['phoneKey']] as $class=>$methods) {
  foreach($methods as $method) if(!method_exists($class,$method)
   || !(new \ReflectionMethod($class,$method))->isPublic())
   throw new \RuntimeException('PHONE_CUSTOMER_METHOD_REQUIRED');
 }
 $schema=\Illuminate\Support\Facades\Schema::getFacadeRoot();
 $columns=[
  'branch_customers'=>['id','branch','revision','name','phone','phone_key','address','area','delivery_notes','latitude','longitude','updated_at'],
  'pos_service_tickets'=>['id','branch','channel','phone_key','customer_name','customer_phone','address','area','delivery_notes','delivery_snapshot'],
 ];
 foreach($columns as $table=>$names) if(!$schema->hasTable($table) || !$schema->hasColumns($table,$names))
  throw new \RuntimeException('PHONE_CUSTOMER_SCHEMA_REQUIRED');
 $result=['ok'=>true];$exit=0;
} catch(\Throwable $error) {
 $result=['ok'=>false,'reason'=>in_array($error->getMessage(),$known,true)?$error->getMessage():'PHONE_CUSTOMER_BRANCH_RUNTIME_FAILED'];
}
while(ob_get_level()>$level)ob_end_clean();echo json_encode($result);exit($exit);
'''


def runtime_probe(c, root, php, stage, phase):
    c.require(phase in ('preflight', 'installed'), 'RUNTIME_PROBE_PHASE_INVALID')
    reasons = {'PHONE_CUSTOMER_BRANCH_RUNTIME_FAILED', 'PHONE_CUSTOMER_ROUTE_INVALID',
               'PHONE_CUSTOMER_MIDDLEWARE_INVALID', 'PHONE_CUSTOMER_METHOD_REQUIRED', 'PHONE_CUSTOMER_SCHEMA_REQUIRED'}
    probe = stage / 'phone-customer-branch-runtime.php'
    probe.write_text(PROBE)
    probe.chmod(0o644)
    try:
        result = c.run([php, str(probe), str(root)], cwd=root)
        report = json.loads(result.stdout)
    except (OSError, ValueError, subprocess.SubprocessError):
        raise RuntimeError('PHONE_CUSTOMER_BRANCH_RUNTIME_FAILED')
    c.require(isinstance(report, dict), 'PHONE_CUSTOMER_BRANCH_RUNTIME_FAILED')
    reason = report.get('reason')
    c.require(result.returncode == 0 and report.get('ok') is True,
              reason if isinstance(reason, str) and reason in reasons else 'PHONE_CUSTOMER_BRANCH_RUNTIME_FAILED')
    print('PHONE_CUSTOMER_BRANCH_RUNTIME_PASS', flush=True)


def apply_update(c, root, revision, php, plan, check_only=False):
    c.require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    c.require(root.is_absolute() and root.resolve() == root and root.is_dir(),
            'APPLICATION_ROOT_INVALID')
    c.safe(root)
    resolved = c.git(root, 'rev-parse', '--verify', revision + '^{commit}')
    c.require(resolved.returncode == 0 and resolved.stdout.decode().strip() == revision,
            'RELEASE_COMMIT_UNAVAILABLE')
    c.require(c.git(root, 'merge-base', '--is-ancestor', BASE_REVISION, revision).returncode == 0,
            'RELEASE_BASE_MISMATCH')

    private = root.parent / 'phone-customer-auto-branch-backups'
    c.safe(private)
    private.mkdir(mode=0o700, exist_ok=True)
    c.require(private.is_dir() and stat.S_IMODE(private.stat().st_mode) == 0o700
            and private.stat().st_uid == os.geteuid(), 'PRIVATE_BACKUP_REQUIRED')
    lock_path = private / 'install.lock'
    c.safe(lock_path)
    with lock_path.open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with tempfile.TemporaryDirectory(prefix='preflight-', dir=private) as temporary:
            stage = Path(temporary)
            originals = {}
            pending = []
            for index, (relative, entry) in enumerate(plan.items()):
                status = entry['status']
                target = root / relative
                before = c.content(target)
                c.require(target.parent.is_dir(), 'REQUIRED_PARENT_MISSING: ' + relative)
                c.require(status == 'A' or before is not None,
                        'REQUIRED_LIVE_FILE_MISSING: ' + relative)
                if before is not None:
                    c.require(target.stat().st_uid == os.geteuid(), 'TARGET_OWNER_MISMATCH: ' + relative)
                mode = stat.S_IMODE(target.stat().st_mode) if before is not None else 0o644
                staged = stage / relative
                staged.parent.mkdir(parents=True, exist_ok=True)
                if before is not None:
                    staged.write_bytes(before)
                originals[relative] = (before, mode)
                patch_path = stage / ('patch-' + str(index) + '.diff')
                patch_path.write_bytes(entry['patch'])
                if c.patch_check(stage, patch_path, reverse=True):
                    continue
                c.require(c.patch_check(stage, patch_path), 'LIVE_CHANGE_NEEDS_REVIEW: ' + relative)
                applied = c.run(['git', 'apply', '--whitespace=nowarn', str(patch_path)], cwd=stage)
                c.require(applied.returncode == 0, 'STAGED_PATCH_FAILED: ' + relative)
                pending.append((relative, entry['patch'], staged.read_bytes()))

            lint_php(c, php, stage)
            runtime_probe(c, root, php, stage, 'preflight')
            if not pending:
                print('PHONE_CUSTOMER_AUTO_BRANCH_OK already_current=1 files=0', flush=True)
                return None
            combined = stage / 'release.diff'
            combined.write_bytes(b''.join(patch for _, patch, _ in pending))
            c.require(c.patch_check(root, combined), 'LIVE_PATCH_PREFLIGHT_FAILED')
            print('PHONE_CUSTOMER_AUTO_BRANCH_PREFLIGHT_OK files=' + str(len(pending)), flush=True)
            if check_only:
                return None

            # Every target must still contain exactly the bytes checked above.
            for relative, _, _ in pending:
                c.require(c.content(root / relative) == originals[relative][0],
                        'LIVE_FILE_CHANGED_DURING_PREFLIGHT: ' + relative)
            prefix = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-')
            backup = Path(tempfile.mkdtemp(prefix=prefix + revision[:10] + '-', dir=private))
            receipt = {'revision': revision, 'base_revision': BASE_REVISION,
                       'status': 'prepared', 'files': []}
            for relative, _, after in pending:
                before, mode = originals[relative]
                if before is not None:
                    saved = backup / relative
                    saved.parent.mkdir(parents=True, exist_ok=True)
                    saved.write_bytes(before)
                    saved.chmod(mode)
                receipt['files'].append({'path': relative, 'mode': mode,
                                         'existed': before is not None,
                                         'before': c.digest(before), 'after': c.digest(after)})
            receipt_path = backup / 'receipt.json'
            receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
            (backup / 'release.diff').write_bytes(combined.read_bytes())
            try:
                # This does not use --index, checkout, reset, or whole-file replacement.
                applied = c.run(['git', 'apply', '--whitespace=nowarn', str(combined)], cwd=root)
                c.require(applied.returncode == 0, 'LIVE_PATCH_FAILED')
                for relative, _, after in pending:
                    c.require(c.content(root / relative) == after,
                            'LIVE_FILE_CHANGED_DURING_INSTALL: ' + relative)
                lint_php(c, php, root)
                runtime_probe(c, root, php, stage, 'installed')
            except BaseException:
                review = []
                # An unowned edit to an existing consumer may still need the new
                # helper or access methods. Keep the source group together instead
                # of removing dependencies underneath that preserved consumer.
                for relative, _, after in pending:
                    before, _ = originals[relative]
                    if before is None:
                        continue
                    try:
                        if c.content(root / relative) not in (before, after):
                            review.append(relative)
                    except (RuntimeError, OSError):
                        review.append(relative)
                if review:
                    receipt['retained_dependency_group'] = [relative for relative, _, _ in pending]
                else:
                    for relative, _, after in reversed(pending):
                        target = root / relative
                        before, mode = originals[relative]
                        try:
                            observed = c.content(target)
                            if observed == after:
                                if before is None:
                                    target.unlink()
                                else:
                                    c.atomic(target, before, mode)
                            elif observed != before:
                                review.append(relative)
                        except (RuntimeError, OSError):
                            # An independently changed new helper can remain unused
                            # after the original consumers have been restored.
                            review.append(relative)
                receipt['status'] = 'rollback_review_required' if review else 'rolled_back'
                receipt['review'] = review
                receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
                print(('PHONE_CUSTOMER_AUTO_BRANCH_ROLLBACK_REVIEW_REQUIRED' if review else
                       'PHONE_CUSTOMER_AUTO_BRANCH_CODE_RESTORED') + '; BACKUP=' + str(backup), flush=True)
                raise
            receipt['status'] = 'installed'
            receipt_path.write_text(json.dumps(receipt, indent=2) + '\n')
            print('PHONE_CUSTOMER_AUTO_BRANCH_OK files=' + str(len(pending))
                  + '; BACKUP=' + str(backup), flush=True)
            return backup


def install(root, revision, php, check_only=False):
    c = load_common(root)
    c.require(re.fullmatch(r'[0-9a-f]{40}', revision), 'FULL_REVISION_REQUIRED')
    c.require(root.is_absolute() and root.resolve() == root and root.is_dir(), 'APPLICATION_ROOT_INVALID')
    c.safe(root)
    c.require(c.git(root, 'rev-parse', '--verify', revision + '^{commit}').stdout.decode().strip() == revision,
              'RELEASE_COMMIT_UNAVAILABLE')
    c.require(c.git(root, 'merge-base', '--is-ancestor', BASE_REVISION, revision).returncode == 0,
              'RELEASE_BASE_MISMATCH')
    plan = build_plan(c, root, revision)
    previous_umask = os.umask(0o022)
    try:
        return apply_update(c, root, revision, php, plan, check_only)
    finally:
        os.umask(previous_umask)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', default='/home/fasakha/public_html')
    parser.add_argument('--revision', required=True)
    parser.add_argument('--php', default='/opt/cpanel/ea-php82/root/usr/bin/php')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    if os.geteuid() != pwd.getpwnam('fasakha').pw_uid:
        raise RuntimeError('RUN_AS_FASAKHA_REQUIRED')
    install(Path(args.root), args.revision, args.php, check_only=args.check)


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, subprocess.SubprocessError, ValueError) as error:
        print('STOPPED: ' + str(error), flush=True)
        raise SystemExit(1)
